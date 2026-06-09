<?php
session_start();
require '../conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['permissao'] !== 'admin') {
    header('Location:../index.html');
    exit;
}

$mensagem = '';
$tipoMensagem = '';
$pacotes = [];
$pacoteEmEdicao = null;

function valorColuna(array $linha, array $nomesPossiveis, $padrao = null)
{
    foreach ($nomesPossiveis as $nome) {
        if (array_key_exists($nome, $linha)) {
            return $linha[$nome];
        }
    }
    return null;
}

function validarDadosPacote(string $nome, string $destino, string $precoRaw): ?string
{
    if ($nome === '' || $destino === '') {
        return 'Preencha nome e destino do pacote.';
    }
    $precoRaw = str_replace(',', '.', $precoRaw);
    if ($precoRaw === '' || !is_numeric($precoRaw) || (float) $precoRaw < 0) {
        return 'Informe um preco valido.';
    }
    return null;
}

function precoNormalizado(string $precoRaw): float
{
    return (float) str_replace(',', '.', trim($precoRaw));
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

function excluirPacoteComDependencias(PDO $pdo, int $pacoteId): array
{
    $tabelasReservas = ['reservas.reservas', '`reservas`.`reservas`'];
    $tabelasPagamento = ['pagamentos.pagamento', '`pagamentos`.`pagamento`'];

    $pdo->beginTransaction();

    try {
        $tabelaReservaUsada = null;
        $colPacote = null;
        $colIdReserva = null;

        foreach ($tabelasReservas as $tabelaReserva) {
            try {
                $stmtCols = $pdo->query('SHOW COLUMNS FROM ' . $tabelaReserva);
                $colunas = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
                if (!$colunas) {
                    continue;
                }

                $colPacoteTmp = escolherColuna($colunas, ['FK_Local', 'FK_Pacote', 'pacote_id', 'id_pacote']);
                if ($colPacoteTmp === null) {
                    continue;
                }

                $tabelaReservaUsada = $tabelaReserva;
                $colPacote = $colPacoteTmp;
                $colIdReserva = escolherColuna($colunas, ['ID_Reserva', 'id_reserva']);
                break;
            } catch (PDOException $e) {
                continue;
            }
        }

        if ($tabelaReservaUsada !== null && $colPacote !== null) {
            if ($colIdReserva !== null) {
                foreach ($tabelasPagamento as $tabelaPagamento) {
                    try {
                        $sqlPag = 'DELETE FROM ' . $tabelaPagamento . ' WHERE ID_Reserva IN (SELECT ' . $colIdReserva . ' FROM ' . $tabelaReservaUsada . ' WHERE ' . $colPacote . ' = :pacote_id)';
                        $stmtPag = $pdo->prepare($sqlPag);
                        $stmtPag->bindValue(':pacote_id', $pacoteId, PDO::PARAM_INT);
                        $stmtPag->execute();
                        break;
                    } catch (PDOException $e) {
                        continue;
                    }
                }
            }

            $sqlRes = 'DELETE FROM ' . $tabelaReservaUsada . ' WHERE ' . $colPacote . ' = :pacote_id';
            $stmtRes = $pdo->prepare($sqlRes);
            $stmtRes->bindValue(':pacote_id', $pacoteId, PDO::PARAM_INT);
            $stmtRes->execute();
        }

        $stmtDelete = $pdo->prepare('DELETE FROM Pacotes.pacotes WHERE ID_Pacote = :id LIMIT 1');
        $stmtDelete->bindValue(':id', $pacoteId, PDO::PARAM_INT);
        $stmtDelete->execute();

        if ($stmtDelete->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'mensagem' => 'Pacote nao encontrado.'];
        }

        $pdo->commit();
        return ['ok' => true, 'mensagem' => 'Pacote excluido com sucesso.'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'mensagem' => 'Nao foi possivel excluir o pacote. Verifique reservas ou permissoes no banco.',
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['adicionar_pacote'])) {
        $nome = isset($_POST['nome']) ? trim((string) $_POST['nome']) : '';
        $destino = isset($_POST['destino']) ? trim((string) $_POST['destino']) : '';
        $precoRaw = isset($_POST['preco']) ? trim((string) $_POST['preco']) : '';
        $erroValidacao = validarDadosPacote($nome, $destino, $precoRaw);

        if ($erroValidacao !== null) {
            $mensagem = $erroValidacao;
            $tipoMensagem = 'erro';
        } else {
            try {
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO Pacotes.pacotes (nome, Destino, preco) VALUES (:nome, :destino, :preco)'
                );
                $stmtInsert->bindValue(':nome', $nome);
                $stmtInsert->bindValue(':destino', $destino);
                $stmtInsert->bindValue(':preco', precoNormalizado($precoRaw));
                $stmtInsert->execute();
                $mensagem = 'Pacote adicionado com sucesso.';
                $tipoMensagem = 'sucesso';
            } catch (PDOException $e) {
                $mensagem = 'Nao foi possivel adicionar o pacote.';
                $tipoMensagem = 'erro';
            }
        }
    } elseif (isset($_POST['editar_pacote'])) {
        $pacoteId = isset($_POST['pacote_id']) ? (int) $_POST['pacote_id'] : 0;
        $nome = isset($_POST['nome']) ? trim((string) $_POST['nome']) : '';
        $destino = isset($_POST['destino']) ? trim((string) $_POST['destino']) : '';
        $precoRaw = isset($_POST['preco']) ? trim((string) $_POST['preco']) : '';
        $erroValidacao = validarDadosPacote($nome, $destino, $precoRaw);

        if ($pacoteId <= 0) {
            $mensagem = 'Pacote invalido para edicao.';
            $tipoMensagem = 'erro';
        } elseif ($erroValidacao !== null) {
            $mensagem = $erroValidacao;
            $tipoMensagem = 'erro';
            $pacoteEmEdicao = [
                'id' => $pacoteId,
                'nome' => $nome,
                'origem' => $destino,
                'preco' => $precoRaw,
            ];
        } else {
            try {
                $stmtUpdate = $pdo->prepare(
                    'UPDATE Pacotes.pacotes SET nome = :nome, Destino = :destino, preco = :preco WHERE ID_Pacote = :id LIMIT 1'
                );
                $stmtUpdate->bindValue(':nome', $nome);
                $stmtUpdate->bindValue(':destino', $destino);
                $stmtUpdate->bindValue(':preco', precoNormalizado($precoRaw));
                $stmtUpdate->bindValue(':id', $pacoteId, PDO::PARAM_INT);
                $stmtUpdate->execute();

                $stmtExiste = $pdo->prepare('SELECT ID_Pacote FROM Pacotes.pacotes WHERE ID_Pacote = :id LIMIT 1');
                $stmtExiste->bindValue(':id', $pacoteId, PDO::PARAM_INT);
                $stmtExiste->execute();

                if ($stmtExiste->fetch(PDO::FETCH_ASSOC)) {
                    header('Location: pacotesADM.php?msg=editado');
                    exit;
                }
                $mensagem = 'Pacote nao encontrado.';
                $tipoMensagem = 'erro';
            } catch (PDOException $e) {
                $mensagem = 'Nao foi possivel atualizar o pacote.';
                $tipoMensagem = 'erro';
                $pacoteEmEdicao = [
                    'id' => $pacoteId,
                    'nome' => $nome,
                    'origem' => $destino,
                    'preco' => $precoRaw,
                ];
            }
        }
    } elseif (isset($_POST['excluir_pacote'])) {
        $pacoteId = isset($_POST['pacote_id']) ? (int) $_POST['pacote_id'] : 0;

        if ($pacoteId <= 0) {
            $mensagem = 'Pacote invalido para exclusao.';
            $tipoMensagem = 'erro';
        } else {
            $resultadoExclusao = excluirPacoteComDependencias($pdo, $pacoteId);
            if ($resultadoExclusao['ok']) {
                header('Location: pacotesADM.php?msg=excluido');
                exit;
            }
            $mensagem = $resultadoExclusao['mensagem'];
            $tipoMensagem = 'erro';
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'editado') {
        $mensagem = 'Pacote atualizado com sucesso.';
        $tipoMensagem = 'sucesso';
    } elseif ($_GET['msg'] === 'excluido') {
        $mensagem = 'Pacote e reservas vinculadas foram excluidos com sucesso.';
        $tipoMensagem = 'sucesso';
    }
}

$editarId = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;
if ($pacoteEmEdicao === null && $editarId > 0) {
    try {
        $stmtEditar = $pdo->prepare(
            'SELECT ID_Pacote, nome, Destino, preco FROM Pacotes.pacotes WHERE ID_Pacote = :id LIMIT 1'
        );
        $stmtEditar->bindValue(':id', $editarId, PDO::PARAM_INT);
        $stmtEditar->execute();
        $linha = $stmtEditar->fetch(PDO::FETCH_ASSOC);
        if ($linha) {
            $pacoteEmEdicao = [
                'id' => (int) valorColuna($linha, ['ID_Pacote']),
                'nome' => (string) valorColuna($linha, ['nome']),
                'origem' => (string) valorColuna($linha, ['Destino']),
                'preco' => valorColuna($linha, ['preco']),
            ];
        } else {
            $mensagem = 'Pacote nao encontrado para edicao.';
            $tipoMensagem = 'erro';
        }
    } catch (PDOException $e) {
        $mensagem = 'Nao foi possivel carregar o pacote para edicao.';
        $tipoMensagem = 'erro';
    }
}

try {
    $stmtPacotes = $pdo->query("SELECT ID_Pacote, nome, Destino, preco FROM Pacotes.pacotes ORDER BY ID_Pacote ASC");
    $linhas = $stmtPacotes->fetchAll(PDO::FETCH_ASSOC);

    foreach ($linhas as $linha) {
        $pacotes[] = [
            'id' => (int) valorColuna($linha, ['ID_Pacote']),
            'nome' => (string) valorColuna($linha, ['nome']),
            'origem' => (string) valorColuna($linha, ['Destino']),
            'preco' => valorColuna($linha, ['preco']),
        ];
    }
} catch (PDOException $e) {
    if ($mensagem === '') {
        $mensagem = 'Nao foi possivel carregar pacotes da tabela Pacotes.pacotes.';
        $tipoMensagem = 'erro';
    }
}

function precoParaInput($preco): string
{
    if ($preco === null || $preco === '') {
        return '';
    }
    return number_format((float) $preco, 2, ',', '');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pacotes - Area do admin</title>
    <style>
        * { box-sizing: border-box; font-family: Arial, sans-serif; }
        body { margin: 0; padding: 24px; background: #203a43; color: #fff; }
        .container { max-width: 920px; margin: 0 auto; }
        .topo { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .acoes { display: flex; gap: 8px; }
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
        .preco { font-weight: bold; margin: 8px 0 12px; }
        .painel-form { background: rgba(255, 255, 255, 0.1); border-radius: 10px; padding: 16px; margin-bottom: 20px; }
        .painel-form h2 { margin-top: 0; font-size: 1.1rem; }
        .painel-form label { display: block; margin-bottom: 4px; font-size: 0.9rem; }
        .painel-form input { width: 100%; max-width: 320px; padding: 8px 10px; margin-bottom: 10px; border: 0; border-radius: 6px; }
        .btn-salvar { background: #2ecc71; color: #fff; font-weight: bold; padding: 10px 14px; border-radius: 8px; border: 0; cursor: pointer; margin-right: 8px; }
        .btn-atualizar { background: #f39c12; color: #fff; font-weight: bold; padding: 10px 14px; border-radius: 8px; border: 0; cursor: pointer; margin-right: 8px; }
        .acoes-card { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .form-inline { display: inline; }
    </style>
</head>
<body>
    <main class="container">
        <div class="topo">
            <h1>Gerenciar pacotes</h1>
            <div class="acoes">
                <a class="btn btn-voltar" href="../admin.php">Voltar</a>
                <a class="btn btn-voltar" href="AdmClientes.php">Ver Clientes</a>
                <a class="btn btn-voltar" href="Reservados.php">Ver Reservas</a>
                <a class="btn btn-logout" href="../logout.php">Logout</a>
            </div>
        </div>

        <?php if ($mensagem !== ''): ?>
            <p class="mensagem <?php echo htmlspecialchars($tipoMensagem); ?>">
                <?php echo htmlspecialchars($mensagem); ?>
            </p>
        <?php endif; ?>

        <?php if ($pacoteEmEdicao !== null): ?>
            <section class="painel-form">
                <h2>Editar pacote #<?php echo (int) $pacoteEmEdicao['id']; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="pacote_id" value="<?php echo (int) $pacoteEmEdicao['id']; ?>">

                    <label for="edit_nome">Nome</label>
                    <input type="text" id="edit_nome" name="nome" required maxlength="120"
                        value="<?php echo htmlspecialchars($pacoteEmEdicao['nome']); ?>">

                    <label for="edit_destino">Destino</label>
                    <input type="text" id="edit_destino" name="destino" required maxlength="120"
                        value="<?php echo htmlspecialchars($pacoteEmEdicao['origem']); ?>">

                    <label for="edit_preco">Preco (R$)</label>
                    <input type="text" id="edit_preco" name="preco" required inputmode="decimal"
                        value="<?php echo htmlspecialchars(precoParaInput($pacoteEmEdicao['preco'])); ?>">

                    <button type="submit" name="editar_pacote" class="btn-atualizar">Salvar alteracoes</button>
                    <a class="btn btn-cancelar" href="pacotesADM.php">Cancelar</a>
                </form>
            </section>
        <?php else: ?>
            <section class="painel-form">
                <h2>Adicionar novo pacote</h2>
                <form method="POST" action="">
                    <label for="nome">Nome</label>
                    <input type="text" id="nome" name="nome" required maxlength="120">

                    <label for="destino">Destino</label>
                    <input type="text" id="destino" name="destino" required maxlength="120">

                    <label for="preco">Preco (R$)</label>
                    <input type="text" id="preco" name="preco" required inputmode="decimal" placeholder="0,00">

                    <button type="submit" name="adicionar_pacote" class="btn-salvar">Adicionar pacote</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="lista">
            <?php if (count($pacotes) === 0): ?>
                <article class="card">
                    <h3>Nenhum pacote encontrado</h3>
                    <p>Use o formulario acima para cadastrar o primeiro pacote.</p>
                </article>
            <?php else: ?>
                <?php foreach ($pacotes as $pacote): ?>
                    <?php $emEdicao = $pacoteEmEdicao !== null && (int) $pacote['id'] === (int) $pacoteEmEdicao['id']; ?>
                    <article class="card<?php echo $emEdicao ? ' card-destaque' : ''; ?>">
                        <h3><?php echo htmlspecialchars($pacote['nome']); ?></h3>
                        <p><?php echo htmlspecialchars($pacote['origem']); ?></p>
                        <?php if ($pacote['preco'] !== null && $pacote['preco'] !== ''): ?>
                            <p class="preco">R$ <?php echo htmlspecialchars(number_format((float) $pacote['preco'], 2, ',', '.')); ?></p>
                        <?php endif; ?>
                        <div class="acoes-card">
                            <a class="btn btn-editar" href="pacotesADM.php?editar=<?php echo (int) $pacote['id']; ?>">Editar</a>
                            <form class="form-inline" method="POST" action="pacotesADM.php" onsubmit="return confirm('Deseja realmente excluir este pacote? Reservas vinculadas tambem serao removidas.');">
                                <input type="hidden" name="pacote_id" value="<?php echo (int) $pacote['id']; ?>">
                                <button type="submit" name="excluir_pacote" class="btn btn-deletar">Deletar</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
