<?php
session_start();
require '../../../conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['permissao'] === 'admin') {
    header('Location: ../../../index.html');
    exit;
}

$usuarioId = (int) $_SESSION['usuario_id'];
$mensagem = '';
$tipoMensagem = '';

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

function buscarFormasPagamento(PDO $pdo)
{
    $padrao = [
        ['id' => 'Pix', 'nome' => 'Pix'],
        ['id' => 'Boleto', 'nome' => 'Boleto'],
        ['id' => 'Cartao', 'nome' => 'Cartao'],
    ];
    $tabelasPagamento = ['pagamentos.pagamento', '`pagamentos`.`pagamento`', 'pagamento'];

    foreach ($tabelasPagamento as $tabelaPagamento) {
        try {
            $stmtColuna = $pdo->query('SHOW COLUMNS FROM ' . $tabelaPagamento . ' LIKE "forma_pagamento"');
            $coluna = $stmtColuna->fetch(PDO::FETCH_ASSOC);
            if ($coluna && isset($coluna['Type'])) {
                $tipo = (string) $coluna['Type'];
                if (preg_match("/^enum\\((.*)\\)$/i", $tipo, $match) === 1) {
                    $itens = str_getcsv($match[1], ',', "'");
                    $formas = [];
                    foreach ($itens as $item) {
                        $valor = trim((string) $item);
                        if ($valor !== '') {
                            $formas[] = ['id' => $valor, 'nome' => $valor];
                        }
                    }
                    if (count($formas) > 0) {
                        return $formas;
                    }
                }
            }
        } catch (PDOException $e) {
            continue;
        }
    }

    return $padrao;
}

function formaPagamentoPermitida(array $formasPagamento, string $formaSelecionada)
{
    foreach ($formasPagamento as $forma) {
        if ((string) $forma['id'] === $formaSelecionada) {
            return true;
        }
    }
    return false;
}

function inserirPagamento(PDO $pdo, int $idReserva, string $formaPagamento)
{
    $tabelasPagamento = ['pagamentos.pagamento'];
    $ultimoErro = '';
    foreach ($tabelasPagamento as $tabelaPagamento) {
        try {
            $sql = 'INSERT INTO ' . $tabelaPagamento . ' (ID_Reserva, forma_pagamento, data_pagamento) VALUES (:id_reserva, :forma_pagamento, CURDATE())';
            $stmtPagamento = $pdo->prepare($sql);
            $stmtPagamento->bindValue(':id_reserva', $idReserva, PDO::PARAM_INT);
            $stmtPagamento->bindValue(':forma_pagamento', $formaPagamento);
            $stmtPagamento->execute();
            return '';
        } catch (PDOException $e) {
            $ultimoErro = $e->getMessage();
        }
    }
    return $ultimoErro;
}

$pacoteId = 0;
if (isset($_GET['pacote_id'])) {
    $pacoteId = (int) $_GET['pacote_id'];
} elseif (isset($_POST['pacote_id'])) {
    $pacoteId = (int) $_POST['pacote_id'];
}

$pacote = null;
if ($pacoteId > 0) {
    try {
        $stmtPacote = $pdo->prepare('SELECT ID_Pacote, nome, Destino, preco FROM Pacotes.pacotes WHERE ID_Pacote = :id LIMIT 1');
        $stmtPacote->bindValue(':id', $pacoteId, PDO::PARAM_INT);
        $stmtPacote->execute();
        $linhaPacote = $stmtPacote->fetch(PDO::FETCH_ASSOC);

        if ($linhaPacote) {
            $pacote = [
                'id' => (int) $linhaPacote['ID_Pacote'],
                'nome' => (string) $linhaPacote['nome'],
                'destino' => (string) $linhaPacote['Destino'],
                'preco' => $linhaPacote['preco'],
            ];
        } else {
            $mensagem = 'Pacote nao encontrado.';
            $tipoMensagem = 'erro';
        }
    } catch (PDOException $e) {
        $mensagem = 'Erro ao carregar pacote: ' . $e->getMessage();
        $tipoMensagem = 'erro';
    }
} else {
    $mensagem = 'Nenhum pacote foi selecionado.';
    $tipoMensagem = 'erro';
}

$formasPagamento = buscarFormasPagamento($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_reserva'])) {
    $formaPagamentoSelecionada = isset($_POST['forma_pagamento_id']) ? trim((string) $_POST['forma_pagamento_id']) : '';

    if ($pacote === null) {
        $mensagem = 'Pacote invalido para finalizar reserva.';
        $tipoMensagem = 'erro';
    } elseif ($formaPagamentoSelecionada === '') {
        $mensagem = 'Selecione uma forma de pagamento.';
        $tipoMensagem = 'erro';
    } elseif (!formaPagamentoPermitida($formasPagamento, $formaPagamentoSelecionada)) {
        $mensagem = 'Forma de pagamento invalida.';
        $tipoMensagem = 'erro';
    } else {
        $inseriu = false;
        $reservaIdCriada = 0;
        $erroDetalhe = '';

        $tabelasReservas = [
            'reservas.reservas',
            '`reservas`.`reservas`',
        ];

        foreach ($tabelasReservas as $tabelaReserva) {
            try {
                $stmtCols = $pdo->query('SHOW COLUMNS FROM ' . $tabelaReserva);
                $colunas = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

                if (!$colunas) {
                    continue;
                }

                $colUsuario = escolherColuna($colunas, ['usuario_id', 'id_usuario', 'ID_Usuario', 'FK_Usuario', 'usuario']);
                $colPacote = escolherColuna($colunas, ['FK_Local', 'FK_Pacote', 'pacote_id', 'id_pacote']);
                $colStatus = escolherColuna($colunas, ['status', 'Status', 'situacao']);
                $colData = escolherColuna($colunas, ['data_reserva', 'data_criacao', 'created_at']);

                if ($colUsuario === null || $colPacote === null || $colStatus === null) {
                    continue;
                }

                $campos = [$colUsuario, $colPacote, $colStatus];
                $values = [':usuario_id', ':pacote_id', ':status'];

                if ($colData !== null) {
                    $campos[] = $colData;
                    $values[] = 'NOW()';
                }

                $sql = 'INSERT INTO ' . $tabelaReserva . ' (' . implode(', ', $campos) . ') VALUES (' . implode(', ', $values) . ')';
                $stmtInsert = $pdo->prepare($sql);
                $stmtInsert->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
                $stmtInsert->bindValue(':pacote_id', $pacote['id'], PDO::PARAM_INT);
                $stmtInsert->bindValue(':status', 'pendente');
                $stmtInsert->execute();
                $reservaIdCriada = (int) $pdo->lastInsertId();

                $inseriu = true;
                break;
            } catch (PDOException $e) {
                $erroDetalhe = $e->getMessage();
            }
        }

        if ($inseriu) {
            $erroPagamento = inserirPagamento($pdo, $reservaIdCriada, $formaPagamentoSelecionada);
            if ($erroPagamento === '') {
                $mensagem = 'Reserva finalizada com sucesso!';
                $tipoMensagem = 'sucesso';
            } else {
                $mensagem = 'Reserva salva, mas nao foi possivel registrar pagamento. Detalhe: ' . $erroPagamento;
                $tipoMensagem = 'erro';
            }
        } else {
            $mensagem = 'Nao foi possivel salvar a reserva. Detalhe: ' . $erroDetalhe;
            $tipoMensagem = 'erro';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrinho - Reserva de Pacote</title>
    <style>
        * { box-sizing: border-box; font-family: Arial, sans-serif; }
        body { margin: 0; padding: 24px; background: #203a43; color: #fff; }
        .container { max-width: 760px; margin: 0 auto; }
        .topo { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .acoes { display: flex; gap: 8px; }
        .btn { text-decoration: none; color: #fff; padding: 10px 14px; border-radius: 8px; font-weight: bold; }
        .btn-voltar { background: #3498db; }
        .btn-logout { background: #e74c3c; }
        .card { background: rgba(255, 255, 255, 0.1); border-radius: 12px; padding: 18px; }
        .linha { margin-bottom: 10px; }
        .label { opacity: 0.85; margin-bottom: 4px; display: block; }
        .valor { font-size: 18px; font-weight: bold; }
        .mensagem { padding: 10px; border-radius: 8px; margin-bottom: 12px; }
        .sucesso { background: rgba(46, 204, 113, 0.25); }
        .erro { background: rgba(231, 76, 60, 0.25); }
        select, button {
            width: 100%;
            border: 0;
            border-radius: 8px;
            padding: 11px;
            font-size: 15px;
        }
        select { margin: 8px 0 14px; }
        button { background: #2ecc71; color: #fff; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>
    <main class="container">
        <div class="topo">
            <h1>Carrinho</h1>
            <div class="acoes">
                <a class="btn btn-voltar" href="../pacotes.php">Voltar aos pacotes</a>
                <a class="btn btn-logout" href="../../../logout.php">Logout</a>
            </div>
        </div>

        <?php if ($mensagem !== ''): ?>
            <p class="mensagem <?php echo htmlspecialchars($tipoMensagem); ?>">
                <?php echo htmlspecialchars($mensagem); ?>
            </p>
        <?php endif; ?>

        <section class="card">
            <?php if ($pacote === null): ?>
                <p>Nao ha pacote no carrinho.</p>
            <?php else: ?>
                <div class="linha">
                    <span class="label">Pacote selecionado</span>
                    <div class="valor"><?php echo htmlspecialchars($pacote['nome']); ?></div>
                </div>
                <div class="linha">
                    <span class="label">Destino</span>
                    <div><?php echo htmlspecialchars($pacote['destino']); ?></div>
                </div>
                <div class="linha">
                    <span class="label">Preco</span>
                    <div class="valor">R$ <?php echo htmlspecialchars(number_format((float) $pacote['preco'], 2, ',', '.')); ?></div>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="pacote_id" value="<?php echo (int) $pacote['id']; ?>">
                    <label for="forma_pagamento_id" class="label">Forma de pagamento</label>
                    <select id="forma_pagamento_id" name="forma_pagamento_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($formasPagamento as $forma): ?>
                            <option value="<?php echo htmlspecialchars((string) $forma['id']); ?>">
                                <?php echo htmlspecialchars($forma['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="finalizar_reserva">Finalizar reserva</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
