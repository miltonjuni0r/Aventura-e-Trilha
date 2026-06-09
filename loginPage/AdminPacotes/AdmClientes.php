<?php
session_start();
require '../conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['permissao'] !== 'admin') {
    header('Location:../index.html');
    exit;
}

$mensagem = '';
$tipoMensagem = '';
$clientes = [];
$clienteEmEdicao = null;

function valorColuna(array $linha, array $nomesPossiveis, $padrao = null)
{
    foreach ($nomesPossiveis as $nome) {
        if (array_key_exists($nome, $linha)) {
            return $linha[$nome];
        }
    }
    return $padrao;
}

function escolherColuna(array $colunas, array $nomes)
{
    $mapa = [];
    foreach ($colunas as $coluna) {
        $mapa[strtolower((string) $coluna)] = $coluna;
    }
    foreach ($nomes as $nome) {
        $chave = strtolower((string) $nome);
        if (isset($mapa[$chave])) {
            return $mapa[$chave];
        }
    }
    return null;
}

function validarDadosCliente(string $usuario, string $cpf, string $telefone, bool $senhaObrigatoria, string $senha): ?string
{
    if ($usuario === '' || $cpf === '' || $telefone === '') {
        return 'Preencha usuario, CPF e telefone.';
    }
    if (preg_match('/\d/', $usuario)) {
        return 'O nome de usuario nao pode conter numeros.';
    }
    if ($senhaObrigatoria && $senha === '') {
        return 'Informe uma senha para o cliente.';
    }
    if ($senha !== '' && strlen($senha) < 4) {
        return 'A senha deve ter pelo menos 4 caracteres.';
    }

    $cpfNumerico = preg_replace('/\D/', '', $cpf);
    $telefoneNumerico = preg_replace('/\D/', '', $telefone);

    if (strlen($cpfNumerico) !== 11) {
        return 'CPF invalido. Informe 11 digitos.';
    }
    if (strlen($telefoneNumerico) < 10 || strlen($telefoneNumerico) > 11) {
        return 'Telefone invalido. Informe DDD + numero.';
    }

    return null;
}

function usuarioJaExiste(PDO $pdo, string $usuario, int $ignorarId = 0): bool
{
    $sql = 'SELECT ID_Usuario FROM usuarios WHERE usuario = :usuario';
    if ($ignorarId > 0) {
        $sql .= ' AND ID_Usuario <> :id';
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':usuario', $usuario);
    if ($ignorarId > 0) {
        $stmt->bindValue(':id', $ignorarId, PDO::PARAM_INT);
    }
    $stmt->execute();

    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function excluirClienteComDependencias(PDO $pdo, int $clienteId): array
{
    $tabelasReservas = ['reservas.reservas', '`reservas`.`reservas`'];
    $tabelasPagamento = ['pagamentos.pagamento', '`pagamentos`.`pagamento`'];

    $pdo->beginTransaction();

    try {
        $tabelaReservaUsada = null;
        $colUsuario = null;
        $colIdReserva = null;

        foreach ($tabelasReservas as $tabelaReserva) {
            try {
                $stmtCols = $pdo->query('SHOW COLUMNS FROM ' . $tabelaReserva);
                $colunas = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
                if (!$colunas) {
                    continue;
                }

                $colUsuarioTmp = escolherColuna($colunas, ['usuario_id', 'id_usuario', 'ID_Usuario', 'FK_Usuario', 'usuario']);
                if ($colUsuarioTmp === null) {
                    continue;
                }

                $tabelaReservaUsada = $tabelaReserva;
                $colUsuario = $colUsuarioTmp;
                $colIdReserva = escolherColuna($colunas, ['ID_Reserva', 'id_reserva']);
                break;
            } catch (PDOException $e) {
                continue;
            }
        }

        if ($tabelaReservaUsada !== null && $colUsuario !== null) {
            if ($colIdReserva !== null) {
                foreach ($tabelasPagamento as $tabelaPagamento) {
                    try {
                        $sqlPag = 'DELETE FROM ' . $tabelaPagamento . ' WHERE ID_Reserva IN (SELECT ' . $colIdReserva . ' FROM ' . $tabelaReservaUsada . ' WHERE ' . $colUsuario . ' = :cliente_id)';
                        $stmtPag = $pdo->prepare($sqlPag);
                        $stmtPag->bindValue(':cliente_id', $clienteId, PDO::PARAM_INT);
                        $stmtPag->execute();
                        break;
                    } catch (PDOException $e) {
                        continue;
                    }
                }
            }

            $sqlRes = 'DELETE FROM ' . $tabelaReservaUsada . ' WHERE ' . $colUsuario . ' = :cliente_id';
            $stmtRes = $pdo->prepare($sqlRes);
            $stmtRes->bindValue(':cliente_id', $clienteId, PDO::PARAM_INT);
            $stmtRes->execute();
        }

        $stmtDelete = $pdo->prepare(
            "DELETE FROM usuarios WHERE ID_Usuario = :id AND permissão = 'usuario' LIMIT 1"
        );
        $stmtDelete->bindValue(':id', $clienteId, PDO::PARAM_INT);
        $stmtDelete->execute();

        if ($stmtDelete->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'mensagem' => 'Cliente nao encontrado.'];
        }

        $pdo->commit();
        return ['ok' => true, 'mensagem' => 'Cliente excluido com sucesso.'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'mensagem' => 'Nao foi possivel excluir o cliente. Verifique reservas ou permissoes no banco.',
        ];
    }
}

function formatarCpf(?string $cpf): string
{
    if ($cpf === null || $cpf === '') {
        return '-';
    }
    $numeros = preg_replace('/\D/', '', $cpf);
    if (strlen($numeros) !== 11) {
        return $cpf;
    }
    return substr($numeros, 0, 3) . '.' . substr($numeros, 3, 3) . '.' . substr($numeros, 6, 3) . '-' . substr($numeros, 9, 2);
}

function formatarTelefone(?string $telefone): string
{
    if ($telefone === null || $telefone === '') {
        return '-';
    }
    $numeros = preg_replace('/\D/', '', $telefone);
    if (strlen($numeros) === 11) {
        return '(' . substr($numeros, 0, 2) . ') ' . substr($numeros, 2, 5) . '-' . substr($numeros, 7, 4);
    }
    if (strlen($numeros) === 10) {
        return '(' . substr($numeros, 0, 2) . ') ' . substr($numeros, 2, 4) . '-' . substr($numeros, 6, 4);
    }
    return $telefone;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['adicionar_cliente'])) {
        $usuario = isset($_POST['usuario']) ? trim((string) $_POST['usuario']) : '';
        $senha = isset($_POST['senha']) ? trim((string) $_POST['senha']) : '';
        $cpf = isset($_POST['cpf']) ? trim((string) $_POST['cpf']) : '';
        $telefone = isset($_POST['telefone']) ? trim((string) $_POST['telefone']) : '';
        $erroValidacao = validarDadosCliente($usuario, $cpf, $telefone, true, $senha);

        if ($erroValidacao !== null) {
            $mensagem = $erroValidacao;
            $tipoMensagem = 'erro';
        } elseif (usuarioJaExiste($pdo, $usuario)) {
            $mensagem = 'Este usuario ja existe.';
            $tipoMensagem = 'erro';
        } else {
            try {
                $cpfNumerico = preg_replace('/\D/', '', $cpf);
                $telefoneNumerico = preg_replace('/\D/', '', $telefone);
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO usuarios (usuario, senha, Telefone, CPF, permissão) VALUES (:usuario, :senha, :telefone, :cpf, :permissao)'
                );
                $stmtInsert->bindValue(':usuario', $usuario);
                $stmtInsert->bindValue(':senha', password_hash($senha, PASSWORD_DEFAULT));
                $stmtInsert->bindValue(':telefone', $telefoneNumerico);
                $stmtInsert->bindValue(':cpf', $cpfNumerico);
                $stmtInsert->bindValue(':permissao', 'usuario');
                $stmtInsert->execute();
                $mensagem = 'Cliente adicionado com sucesso.';
                $tipoMensagem = 'sucesso';
            } catch (PDOException $e) {
                $mensagem = 'Nao foi possivel adicionar o cliente.';
                $tipoMensagem = 'erro';
            }
        }
    } elseif (isset($_POST['editar_cliente'])) {
        $clienteId = isset($_POST['cliente_id']) ? (int) $_POST['cliente_id'] : 0;
        $usuario = isset($_POST['usuario']) ? trim((string) $_POST['usuario']) : '';
        $senha = isset($_POST['senha']) ? trim((string) $_POST['senha']) : '';
        $cpf = isset($_POST['cpf']) ? trim((string) $_POST['cpf']) : '';
        $telefone = isset($_POST['telefone']) ? trim((string) $_POST['telefone']) : '';
        $erroValidacao = validarDadosCliente($usuario, $cpf, $telefone, false, $senha);

        if ($clienteId <= 0) {
            $mensagem = 'Cliente invalido para edicao.';
            $tipoMensagem = 'erro';
        } elseif ($erroValidacao !== null) {
            $mensagem = $erroValidacao;
            $tipoMensagem = 'erro';
            $clienteEmEdicao = [
                'id' => $clienteId,
                'nome' => $usuario,
                'cpf' => $cpf,
                'telefone' => $telefone,
            ];
        } elseif (usuarioJaExiste($pdo, $usuario, $clienteId)) {
            $mensagem = 'Este usuario ja existe.';
            $tipoMensagem = 'erro';
            $clienteEmEdicao = [
                'id' => $clienteId,
                'nome' => $usuario,
                'cpf' => $cpf,
                'telefone' => $telefone,
            ];
        } else {
            try {
                $cpfNumerico = preg_replace('/\D/', '', $cpf);
                $telefoneNumerico = preg_replace('/\D/', '', $telefone);

                if ($senha !== '') {
                    $sqlUpdate = "UPDATE usuarios SET usuario = :usuario, senha = :senha, Telefone = :telefone, CPF = :cpf WHERE ID_Usuario = :id AND permissão = 'usuario' LIMIT 1";
                    $stmtUpdate = $pdo->prepare($sqlUpdate);
                    $stmtUpdate->bindValue(':senha', password_hash($senha, PASSWORD_DEFAULT));
                } else {
                    $sqlUpdate = "UPDATE usuarios SET usuario = :usuario, Telefone = :telefone, CPF = :cpf WHERE ID_Usuario = :id AND permissão = 'usuario' LIMIT 1";
                    $stmtUpdate = $pdo->prepare($sqlUpdate);
                }

                $stmtUpdate->bindValue(':usuario', $usuario);
                $stmtUpdate->bindValue(':telefone', $telefoneNumerico);
                $stmtUpdate->bindValue(':cpf', $cpfNumerico);
                $stmtUpdate->bindValue(':id', $clienteId, PDO::PARAM_INT);
                $stmtUpdate->execute();

                $stmtExiste = $pdo->prepare(
                    "SELECT ID_Usuario FROM usuarios WHERE ID_Usuario = :id AND permissão = 'usuario' LIMIT 1"
                );
                $stmtExiste->bindValue(':id', $clienteId, PDO::PARAM_INT);
                $stmtExiste->execute();

                if ($stmtExiste->fetch(PDO::FETCH_ASSOC)) {
                    header('Location: AdmClientes.php?msg=editado');
                    exit;
                }
                $mensagem = 'Cliente nao encontrado.';
                $tipoMensagem = 'erro';
            } catch (PDOException $e) {
                $mensagem = 'Nao foi possivel atualizar o cliente.';
                $tipoMensagem = 'erro';
                $clienteEmEdicao = [
                    'id' => $clienteId,
                    'nome' => $usuario,
                    'cpf' => $cpf,
                    'telefone' => $telefone,
                ];
            }
        }
    } elseif (isset($_POST['excluir_cliente'])) {
        $clienteId = isset($_POST['cliente_id']) ? (int) $_POST['cliente_id'] : 0;

        if ($clienteId <= 0) {
            $mensagem = 'Cliente invalido para exclusao.';
            $tipoMensagem = 'erro';
        } else {
            $resultadoExclusao = excluirClienteComDependencias($pdo, $clienteId);
            if ($resultadoExclusao['ok']) {
                header('Location: AdmClientes.php?msg=excluido');
                exit;
            }
            $mensagem = $resultadoExclusao['mensagem'];
            $tipoMensagem = 'erro';
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'editado') {
        $mensagem = 'Cliente atualizado com sucesso.';
        $tipoMensagem = 'sucesso';
    } elseif ($_GET['msg'] === 'excluido') {
        $mensagem = 'Cliente e reservas vinculadas foram excluidos com sucesso.';
        $tipoMensagem = 'sucesso';
    }
}

$editarId = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;
if ($clienteEmEdicao === null && $editarId > 0) {
    try {
        $stmtEditar = $pdo->prepare(
            "SELECT ID_Usuario, usuario, Telefone, CPF FROM usuarios WHERE ID_Usuario = :id AND permissão = 'usuario' LIMIT 1"
        );
        $stmtEditar->bindValue(':id', $editarId, PDO::PARAM_INT);
        $stmtEditar->execute();
        $linha = $stmtEditar->fetch(PDO::FETCH_ASSOC);
        if ($linha) {
            $clienteEmEdicao = [
                'id' => (int) valorColuna($linha, ['ID_Usuario']),
                'nome' => (string) valorColuna($linha, ['usuario'], ''),
                'telefone' => valorColuna($linha, ['Telefone']),
                'cpf' => valorColuna($linha, ['CPF']),
            ];
        } else {
            $mensagem = 'Cliente nao encontrado para edicao.';
            $tipoMensagem = 'erro';
        }
    } catch (PDOException $e) {
        $mensagem = 'Nao foi possivel carregar o cliente para edicao.';
        $tipoMensagem = 'erro';
    }
}

try {
    $stmtClientes = $pdo->query(
        "SELECT ID_Usuario, usuario, Telefone, CPF, permissão
         FROM usuarios
         WHERE permissão = 'usuario'
         ORDER BY usuario ASC"
    );
    $linhas = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

    foreach ($linhas as $linha) {
        $clientes[] = [
            'id' => (int) valorColuna($linha, ['ID_Usuario']),
            'nome' => (string) valorColuna($linha, ['usuario'], ''),
            'telefone' => valorColuna($linha, ['Telefone']),
            'cpf' => valorColuna($linha, ['CPF']),
        ];
    }
} catch (PDOException $e) {
    if ($mensagem === '') {
        $mensagem = 'Nao foi possivel carregar os clientes.';
        $tipoMensagem = 'erro';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Area do admin</title>
    <style>
        * { box-sizing: border-box; font-family: Arial, sans-serif; }
        body { margin: 0; padding: 24px; background: #203a43; color: #fff; }
        .container { max-width: 920px; margin: 0 auto; }
        .topo { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .acoes { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { text-decoration: none; color: #fff; padding: 10px 14px; border-radius: 8px; font-weight: bold; border: 0; cursor: pointer; display: inline-block; font-size: 14px; }
        .btn-voltar { background: #3498db; }
        .btn-logout { background: #e74c3c; }
        .btn-deletar { background: #c0392b; }
        .btn-editar { background: #f39c12; }
        .btn-cancelar { background: #7f8c8d; }
        .mensagem { padding: 10px; border-radius: 8px; margin-bottom: 12px; }
        .sucesso { background: rgba(46, 204, 113, 0.25); }
        .erro { background: rgba(231, 76, 60, 0.25); }
        .lista { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
        .card { background: rgba(255, 255, 255, 0.1); border-radius: 10px; padding: 14px; }
        .card-destaque { outline: 2px solid #f39c12; }
        .card h3 { margin-top: 0; margin-bottom: 6px; }
        .card p { margin: 4px 0; font-size: 0.95rem; }
        .card-id { opacity: 0.75; font-size: 0.85rem; }
        .painel-form { background: rgba(255, 255, 255, 0.1); border-radius: 10px; padding: 16px; margin-bottom: 20px; }
        .painel-form h2 { margin-top: 0; font-size: 1.1rem; }
        .painel-form label { display: block; margin-bottom: 4px; font-size: 0.9rem; }
        .painel-form input { width: 100%; max-width: 320px; padding: 8px 10px; margin-bottom: 10px; border: 0; border-radius: 6px; }
        .btn-salvar { background: #2ecc71; color: #fff; font-weight: bold; padding: 10px 14px; border-radius: 8px; border: 0; cursor: pointer; margin-right: 8px; }
        .btn-atualizar { background: #f39c12; color: #fff; font-weight: bold; padding: 10px 14px; border-radius: 8px; border: 0; cursor: pointer; margin-right: 8px; }
        .acoes-card { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .form-inline { display: inline; }
        .dica { font-size: 0.85rem; opacity: 0.85; margin: -6px 0 10px; }
    </style>
</head>
<body>
    <main class="container">
        <div class="topo">
            <h1>Gerenciar clientes</h1>
            <div class="acoes">
                <a class="btn btn-voltar" href="../admin.php">Voltar</a>
                <a class="btn btn-voltar" href="pacotesADM.php">Editar Pacotes</a>
                <a class="btn btn-voltar" href="Reservados.php">Ver Reservas</a>
                <a class="btn btn-logout" href="../logout.php">Logout</a>
            </div>
        </div>

        <?php if ($mensagem !== ''): ?>
            <p class="mensagem <?php echo htmlspecialchars($tipoMensagem); ?>">
                <?php echo htmlspecialchars($mensagem); ?>
            </p>
        <?php endif; ?>

        <?php if ($clienteEmEdicao !== null): ?>
            <section class="painel-form">
                <h2>Editar cliente #<?php echo (int) $clienteEmEdicao['id']; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="cliente_id" value="<?php echo (int) $clienteEmEdicao['id']; ?>">

                    <label for="edit_usuario">Usuario</label>
                    <input type="text" id="edit_usuario" name="usuario" required maxlength="80"
                        pattern="[A-Za-zÀ-ÿ\s]+" title="Use apenas letras."
                        value="<?php echo htmlspecialchars($clienteEmEdicao['nome']); ?>">

                    <label for="edit_senha">Nova senha</label>
                    <input type="password" id="edit_senha" name="senha" minlength="4" maxlength="120">
                    <p class="dica">Deixe em branco para manter a senha atual.</p>

                    <label for="edit_cpf">CPF</label>
                    <input type="text" id="edit_cpf" name="cpf" required maxlength="11" inputmode="numeric"
                        pattern="[0-9]{11}" value="<?php echo htmlspecialchars((string) $clienteEmEdicao['cpf']); ?>">

                    <label for="edit_telefone">Telefone</label>
                    <input type="text" id="edit_telefone" name="telefone" required maxlength="11" inputmode="numeric"
                        pattern="[0-9]{10,11}" value="<?php echo htmlspecialchars((string) $clienteEmEdicao['telefone']); ?>">

                    <button type="submit" name="editar_cliente" class="btn-atualizar">Salvar alteracoes</button>
                    <a class="btn btn-cancelar" href="AdmClientes.php">Cancelar</a>
                </form>
            </section>
        <?php else: ?>
            <section class="painel-form">
                <h2>Adicionar novo cliente</h2>
                <form method="POST" action="">
                    <label for="usuario">Usuario</label>
                    <input type="text" id="usuario" name="usuario" required maxlength="80"
                        pattern="[A-Za-zÀ-ÿ\s]+" title="Use apenas letras.">

                    <label for="senha">Senha</label>
                    <input type="password" id="senha" name="senha" required minlength="4" maxlength="120">

                    <label for="cpf">CPF</label>
                    <input type="text" id="cpf" name="cpf" required maxlength="11" inputmode="numeric" pattern="[0-9]{11}">

                    <label for="telefone">Telefone</label>
                    <input type="text" id="telefone" name="telefone" required maxlength="11" inputmode="numeric" pattern="[0-9]{10,11}">

                    <button type="submit" name="adicionar_cliente" class="btn-salvar">Adicionar cliente</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="lista">
            <?php if (count($clientes) === 0): ?>
                <article class="card">
                    <h3>Nenhum cliente encontrado</h3>
                    <p>Use o formulario acima para cadastrar o primeiro cliente.</p>
                </article>
            <?php else: ?>
                <?php foreach ($clientes as $cliente): ?>
                    <?php $emEdicao = $clienteEmEdicao !== null && (int) $cliente['id'] === (int) $clienteEmEdicao['id']; ?>
                    <article class="card<?php echo $emEdicao ? ' card-destaque' : ''; ?>">
                        <p class="card-id">#<?php echo (int) $cliente['id']; ?></p>
                        <h3><?php echo htmlspecialchars($cliente['nome']); ?></h3>
                        <p>CPF: <?php echo htmlspecialchars(formatarCpf($cliente['cpf'])); ?></p>
                        <p>Telefone: <?php echo htmlspecialchars(formatarTelefone($cliente['telefone'])); ?></p>
                        <div class="acoes-card">
                            <a class="btn btn-editar" href="AdmClientes.php?editar=<?php echo (int) $cliente['id']; ?>">Editar</a>
                            <form class="form-inline" method="POST" action="AdmClientes.php" onsubmit="return confirm('Deseja realmente excluir este cliente? Reservas vinculadas tambem serao removidas.');">
                                <input type="hidden" name="cliente_id" value="<?php echo (int) $cliente['id']; ?>">
                                <button type="submit" name="excluir_cliente" class="btn btn-deletar">Deletar</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    <script>
        function permitirApenasNumeros(evento) {
            evento.target.value = evento.target.value.replace(/\D/g, '');
        }

        function permitirApenasLetras(evento) {
            evento.target.value = evento.target.value.replace(/[^A-Za-zÀ-ÿ\s]/g, '');
        }

        ['usuario', 'edit_usuario'].forEach(function (id) {
            var campo = document.getElementById(id);
            if (campo) {
                campo.addEventListener('input', permitirApenasLetras);
            }
        });

        ['cpf', 'telefone', 'edit_cpf', 'edit_telefone'].forEach(function (id) {
            var campo = document.getElementById(id);
            if (campo) {
                campo.addEventListener('input', permitirApenasNumeros);
            }
        });
    </script>
</body>
</html>
