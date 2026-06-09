<?php
session_start();
require '../conexao.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['permissao'] !== 'admin') {
    header('Location:../index.html');
    exit;
}

$mensagem = '';
$tipoMensagem = '';
$reservas = [];
$reservaEmEdicao = null;
$clientesOpcoes = [];
$pacotesOpcoes = [];
$formasPagamento = [];
$statusPermitidos = [];

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

function detectarEstruturaReservas(PDO $pdo): ?array
{
    $tabelasReservas = ['reservas.reservas', '`reservas`.`reservas`'];

    foreach ($tabelasReservas as $tabelaReserva) {
        try {
            $stmtCols = $pdo->query('SHOW COLUMNS FROM ' . $tabelaReserva);
            $colunas = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
            if (!$colunas) {
                continue;
            }

            $colId = escolherColuna($colunas, ['ID_Reserva', 'id_reserva']);
            $colUsuario = escolherColuna($colunas, ['usuario_id', 'id_usuario', 'ID_Usuario', 'FK_Usuario', 'usuario']);
            $colPacote = escolherColuna($colunas, ['FK_Local', 'FK_Pacote', 'pacote_id', 'id_pacote']);
            $colStatus = escolherColuna($colunas, ['status', 'Status', 'situacao']);

            if ($colId === null || $colUsuario === null || $colPacote === null || $colStatus === null) {
                continue;
            }

            return [
                'tabela' => $tabelaReserva,
                'col_id' => $colId,
                'col_usuario' => $colUsuario,
                'col_pacote' => $colPacote,
                'col_status' => $colStatus,
                'col_data' => escolherColuna($colunas, ['data_reserva', 'data_criacao', 'created_at']),
            ];
        } catch (PDOException $e) {
            continue;
        }
    }

    return null;
}

function buscarFormasPagamento(PDO $pdo): array
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

function buscarStatusPermitidos(PDO $pdo, array $estrutura): array
{
    $padrao = [
        ['id' => 'pendente', 'nome' => 'Pendente'],
        ['id' => 'confirmado', 'nome' => 'Confirmado'],
        ['id' => 'cancelado', 'nome' => 'Cancelado'],
    ];

    try {
        $stmtColuna = $pdo->query(
            'SHOW COLUMNS FROM ' . $estrutura['tabela'] . ' LIKE ' . $pdo->quote($estrutura['col_status'])
        );
        $coluna = $stmtColuna->fetch(PDO::FETCH_ASSOC);
        if ($coluna && isset($coluna['Type'])) {
            $tipo = (string) $coluna['Type'];
            if (preg_match("/^enum\\((.*)\\)$/i", $tipo, $match) === 1) {
                $itens = str_getcsv($match[1], ',', "'");
                $status = [];
                foreach ($itens as $item) {
                    $valor = trim((string) $item);
                    if ($valor !== '') {
                        $status[] = ['id' => $valor, 'nome' => ucfirst($valor)];
                    }
                }
                if (count($status) > 0) {
                    return $status;
                }
            }
        }
    } catch (PDOException $e) {
        return $padrao;
    }

    return $padrao;
}

function valorPermitido(array $opcoes, string $valor): bool
{
    foreach ($opcoes as $opcao) {
        if ((string) $opcao['id'] === $valor) {
            return true;
        }
    }
    return false;
}

function validarDadosReserva(
    int $clienteId,
    int $pacoteId,
    string $status,
    string $formaPagamento,
    array $statusPermitidos,
    array $formasPagamento
): ?string {
    if ($clienteId <= 0 || $pacoteId <= 0) {
        return 'Selecione cliente e pacote.';
    }
    if ($status === '') {
        return 'Selecione o status da reserva.';
    }
    if ($formaPagamento === '') {
        return 'Selecione a forma de pagamento.';
    }
    if (!valorPermitido($statusPermitidos, $status)) {
        return 'Status invalido.';
    }
    if (!valorPermitido($formasPagamento, $formaPagamento)) {
        return 'Forma de pagamento invalida.';
    }
    return null;
}

function clienteExiste(PDO $pdo, int $clienteId): bool
{
    $stmt = $pdo->prepare(
        "SELECT ID_Usuario FROM usuarios WHERE ID_Usuario = :id AND permissão = 'usuario' LIMIT 1"
    );
    $stmt->bindValue(':id', $clienteId, PDO::PARAM_INT);
    $stmt->execute();
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function pacoteExiste(PDO $pdo, int $pacoteId): bool
{
    $stmt = $pdo->prepare('SELECT ID_Pacote FROM Pacotes.pacotes WHERE ID_Pacote = :id LIMIT 1');
    $stmt->bindValue(':id', $pacoteId, PDO::PARAM_INT);
    $stmt->execute();
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function salvarPagamentoReserva(PDO $pdo, int $reservaId, string $formaPagamento): string
{
    $tabelasPagamento = ['pagamentos.pagamento', '`pagamentos`.`pagamento`'];
    $ultimoErro = '';

    foreach ($tabelasPagamento as $tabelaPagamento) {
        try {
            $stmtCheck = $pdo->prepare('SELECT ID_Reserva FROM ' . $tabelaPagamento . ' WHERE ID_Reserva = :id LIMIT 1');
            $stmtCheck->bindValue(':id', $reservaId, PDO::PARAM_INT);
            $stmtCheck->execute();
            $existe = (bool) $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existe) {
                $sql = 'UPDATE ' . $tabelaPagamento . ' SET forma_pagamento = :forma_pagamento WHERE ID_Reserva = :id LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':forma_pagamento', $formaPagamento);
                $stmt->bindValue(':id', $reservaId, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $sql = 'INSERT INTO ' . $tabelaPagamento . ' (ID_Reserva, forma_pagamento, data_pagamento) VALUES (:id, :forma_pagamento, CURDATE())';
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':id', $reservaId, PDO::PARAM_INT);
                $stmt->bindValue(':forma_pagamento', $formaPagamento);
                $stmt->execute();
            }
            return '';
        } catch (PDOException $e) {
            $ultimoErro = $e->getMessage();
        }
    }

    return $ultimoErro !== '' ? $ultimoErro : 'Nao foi possivel salvar o pagamento.';
}

function excluirReservaComDependencias(PDO $pdo, array $estrutura, int $reservaId): array
{
    $tabelasPagamento = ['pagamentos.pagamento', '`pagamentos`.`pagamento`'];

    $pdo->beginTransaction();

    try {
        foreach ($tabelasPagamento as $tabelaPagamento) {
            try {
                $stmtPag = $pdo->prepare('DELETE FROM ' . $tabelaPagamento . ' WHERE ID_Reserva = :id');
                $stmtPag->bindValue(':id', $reservaId, PDO::PARAM_INT);
                $stmtPag->execute();
                break;
            } catch (PDOException $e) {
                continue;
            }
        }

        $sqlRes = 'DELETE FROM ' . $estrutura['tabela'] . ' WHERE ' . $estrutura['col_id'] . ' = :id LIMIT 1';
        $stmtRes = $pdo->prepare($sqlRes);
        $stmtRes->bindValue(':id', $reservaId, PDO::PARAM_INT);
        $stmtRes->execute();

        if ($stmtRes->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'mensagem' => 'Reserva nao encontrada.'];
        }

        $pdo->commit();
        return ['ok' => true, 'mensagem' => 'Reserva excluida com sucesso.'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'mensagem' => 'Nao foi possivel excluir a reserva. Verifique permissoes no banco.',
        ];
    }
}

function buscarPagamentosPorReservas(PDO $pdo, array $idsReserva): array
{
    if (count($idsReserva) === 0) {
        return [];
    }

    $mapa = [];
    $tabelasPagamento = ['pagamentos.pagamento', '`pagamentos`.`pagamento`'];
    $placeholders = implode(',', array_fill(0, count($idsReserva), '?'));

    foreach ($tabelasPagamento as $tabelaPagamento) {
        try {
            $sql = 'SELECT ID_Reserva, forma_pagamento, data_pagamento FROM ' . $tabelaPagamento . ' WHERE ID_Reserva IN (' . $placeholders . ')';
            $stmt = $pdo->prepare($sql);
            foreach (array_values($idsReserva) as $indice => $id) {
                $stmt->bindValue($indice + 1, (int) $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
                $idReserva = (int) valorColuna($linha, ['ID_Reserva', 'id_reserva'], 0);
                if ($idReserva > 0) {
                    $mapa[$idReserva] = [
                        'forma' => (string) valorColuna($linha, ['forma_pagamento'], ''),
                        'data' => valorColuna($linha, ['data_pagamento']),
                    ];
                }
            }
            if (count($mapa) > 0) {
                return $mapa;
            }
        } catch (PDOException $e) {
            continue;
        }
    }

    return $mapa;
}

function listarReservas(PDO $pdo, array $estrutura): array
{
    $alias = 'r';
    $campos = [
        $alias . '.' . $estrutura['col_id'] . ' AS id_reserva',
        $alias . '.' . $estrutura['col_usuario'] . ' AS cliente_id',
        $alias . '.' . $estrutura['col_pacote'] . ' AS pacote_id',
        $alias . '.' . $estrutura['col_status'] . ' AS status',
    ];

    if ($estrutura['col_data'] !== null) {
        $campos[] = $alias . '.' . $estrutura['col_data'] . ' AS data_reserva';
    }

    $campos[] = 'u.usuario AS nome_cliente';
    $campos[] = 'p.nome AS nome_pacote';
    $campos[] = 'p.Destino AS destino_pacote';
    $campos[] = 'p.preco AS preco_pacote';

    $sql = 'SELECT ' . implode(', ', $campos) . '
            FROM ' . $estrutura['tabela'] . ' ' . $alias . '
            LEFT JOIN usuarios u ON u.ID_Usuario = ' . $alias . '.' . $estrutura['col_usuario'] . '
            LEFT JOIN Pacotes.pacotes p ON p.ID_Pacote = ' . $alias . '.' . $estrutura['col_pacote'] . '
            ORDER BY ' . $alias . '.' . $estrutura['col_id'] . ' DESC';

    $stmt = $pdo->query($sql);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];

    foreach ($linhas as $linha) {
        $ids[] = (int) valorColuna($linha, ['id_reserva'], 0);
    }

    $pagamentos = buscarPagamentosPorReservas($pdo, $ids);
    $reservas = [];

    foreach ($linhas as $linha) {
        $idReserva = (int) valorColuna($linha, ['id_reserva'], 0);
        $pagamento = $pagamentos[$idReserva] ?? null;

        $reservas[] = [
            'id' => $idReserva,
            'cliente_id' => (int) valorColuna($linha, ['cliente_id'], 0),
            'pacote_id' => (int) valorColuna($linha, ['pacote_id'], 0),
            'status' => (string) valorColuna($linha, ['status'], ''),
            'data_reserva' => valorColuna($linha, ['data_reserva']),
            'nome_cliente' => (string) valorColuna($linha, ['nome_cliente'], 'Cliente removido'),
            'nome_pacote' => (string) valorColuna($linha, ['nome_pacote'], 'Pacote removido'),
            'destino_pacote' => (string) valorColuna($linha, ['destino_pacote'], ''),
            'preco_pacote' => valorColuna($linha, ['preco_pacote']),
            'forma_pagamento' => $pagamento['forma'] ?? '-',
            'data_pagamento' => $pagamento['data'] ?? null,
        ];
    }

    return $reservas;
}

function buscarReservaPorId(PDO $pdo, array $estrutura, int $reservaId): ?array
{
    $sql = 'SELECT ' . $estrutura['col_id'] . ' AS id_reserva,
                   ' . $estrutura['col_usuario'] . ' AS cliente_id,
                   ' . $estrutura['col_pacote'] . ' AS pacote_id,
                   ' . $estrutura['col_status'] . ' AS status
            FROM ' . $estrutura['tabela'] . '
            WHERE ' . $estrutura['col_id'] . ' = :id
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $reservaId, PDO::PARAM_INT);
    $stmt->execute();
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linha) {
        return null;
    }

    $pagamentos = buscarPagamentosPorReservas($pdo, [(int) $linha['id_reserva']]);
    $idReserva = (int) $linha['id_reserva'];
    $pagamento = $pagamentos[$idReserva] ?? null;

    return [
        'id' => $idReserva,
        'cliente_id' => (int) valorColuna($linha, ['cliente_id'], 0),
        'pacote_id' => (int) valorColuna($linha, ['pacote_id'], 0),
        'status' => (string) valorColuna($linha, ['status'], ''),
        'forma_pagamento' => $pagamento['forma'] ?? '',
    ];
}

function inserirReserva(PDO $pdo, array $estrutura, int $clienteId, int $pacoteId, string $status): array
{
    $campos = [$estrutura['col_usuario'], $estrutura['col_pacote'], $estrutura['col_status']];
    $values = [':cliente_id', ':pacote_id', ':status'];

    if ($estrutura['col_data'] !== null) {
        $campos[] = $estrutura['col_data'];
        $values[] = 'NOW()';
    }

    $sql = 'INSERT INTO ' . $estrutura['tabela'] . ' (' . implode(', ', $campos) . ') VALUES (' . implode(', ', $values) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cliente_id', $clienteId, PDO::PARAM_INT);
    $stmt->bindValue(':pacote_id', $pacoteId, PDO::PARAM_INT);
    $stmt->bindValue(':status', $status);
    $stmt->execute();

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

function atualizarReserva(PDO $pdo, array $estrutura, int $reservaId, int $clienteId, int $pacoteId, string $status): bool
{
    $sql = 'UPDATE ' . $estrutura['tabela'] . '
            SET ' . $estrutura['col_usuario'] . ' = :cliente_id,
                ' . $estrutura['col_pacote'] . ' = :pacote_id,
                ' . $estrutura['col_status'] . ' = :status
            WHERE ' . $estrutura['col_id'] . ' = :id
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cliente_id', $clienteId, PDO::PARAM_INT);
    $stmt->bindValue(':pacote_id', $pacoteId, PDO::PARAM_INT);
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':id', $reservaId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount() > 0;
}

function formatarData(?string $data): string
{
    if ($data === null || $data === '') {
        return '-';
    }
    $timestamp = strtotime($data);
    if ($timestamp === false) {
        return $data;
    }
    return date('d/m/Y', $timestamp);
}

$estruturaReservas = detectarEstruturaReservas($pdo);

if ($estruturaReservas === null) {
    $mensagem = 'Nao foi possivel identificar a tabela de reservas no banco.';
    $tipoMensagem = 'erro';
} else {
    $formasPagamento = buscarFormasPagamento($pdo);
    $statusPermitidos = buscarStatusPermitidos($pdo, $estruturaReservas);

    try {
        $stmtClientes = $pdo->query(
            "SELECT ID_Usuario, usuario FROM usuarios WHERE permissão = 'usuario' ORDER BY usuario ASC"
        );
        foreach ($stmtClientes->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $clientesOpcoes[] = [
                'id' => (int) valorColuna($linha, ['ID_Usuario']),
                'nome' => (string) valorColuna($linha, ['usuario'], ''),
            ];
        }

        $stmtPacotes = $pdo->query('SELECT ID_Pacote, nome, Destino FROM Pacotes.pacotes ORDER BY nome ASC');
        foreach ($stmtPacotes->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $pacotesOpcoes[] = [
                'id' => (int) valorColuna($linha, ['ID_Pacote']),
                'nome' => (string) valorColuna($linha, ['nome'], ''),
                'destino' => (string) valorColuna($linha, ['Destino'], ''),
            ];
        }
    } catch (PDOException $e) {
        $mensagem = 'Nao foi possivel carregar clientes ou pacotes.';
        $tipoMensagem = 'erro';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $clienteId = isset($_POST['cliente_id']) ? (int) $_POST['cliente_id'] : 0;
        $pacoteId = isset($_POST['pacote_id']) ? (int) $_POST['pacote_id'] : 0;
        $status = isset($_POST['status']) ? trim((string) $_POST['status']) : '';
        $formaPagamento = isset($_POST['forma_pagamento']) ? trim((string) $_POST['forma_pagamento']) : '';
        $erroValidacao = validarDadosReserva(
            $clienteId,
            $pacoteId,
            $status,
            $formaPagamento,
            $statusPermitidos,
            $formasPagamento
        );

        if (isset($_POST['adicionar_reserva'])) {
            if ($erroValidacao !== null) {
                $mensagem = $erroValidacao;
                $tipoMensagem = 'erro';
            } elseif (!clienteExiste($pdo, $clienteId)) {
                $mensagem = 'Cliente selecionado nao encontrado.';
                $tipoMensagem = 'erro';
            } elseif (!pacoteExiste($pdo, $pacoteId)) {
                $mensagem = 'Pacote selecionado nao encontrado.';
                $tipoMensagem = 'erro';
            } else {
                try {
                    $resultado = inserirReserva($pdo, $estruturaReservas, $clienteId, $pacoteId, $status);
                    $erroPagamento = salvarPagamentoReserva($pdo, $resultado['id'], $formaPagamento);
                    if ($erroPagamento === '') {
                        $mensagem = 'Reserva adicionada com sucesso.';
                        $tipoMensagem = 'sucesso';
                    } else {
                        $mensagem = 'Reserva salva, mas nao foi possivel registrar pagamento. Detalhe: ' . $erroPagamento;
                        $tipoMensagem = 'erro';
                    }
                } catch (PDOException $e) {
                    $mensagem = 'Nao foi possivel adicionar a reserva.';
                    $tipoMensagem = 'erro';
                }
            }
        } elseif (isset($_POST['editar_reserva'])) {
            $reservaId = isset($_POST['reserva_id']) ? (int) $_POST['reserva_id'] : 0;

            if ($reservaId <= 0) {
                $mensagem = 'Reserva invalida para edicao.';
                $tipoMensagem = 'erro';
            } elseif ($erroValidacao !== null) {
                $mensagem = $erroValidacao;
                $tipoMensagem = 'erro';
                $reservaEmEdicao = [
                    'id' => $reservaId,
                    'cliente_id' => $clienteId,
                    'pacote_id' => $pacoteId,
                    'status' => $status,
                    'forma_pagamento' => $formaPagamento,
                ];
            } elseif (!clienteExiste($pdo, $clienteId)) {
                $mensagem = 'Cliente selecionado nao encontrado.';
                $tipoMensagem = 'erro';
                $reservaEmEdicao = [
                    'id' => $reservaId,
                    'cliente_id' => $clienteId,
                    'pacote_id' => $pacoteId,
                    'status' => $status,
                    'forma_pagamento' => $formaPagamento,
                ];
            } elseif (!pacoteExiste($pdo, $pacoteId)) {
                $mensagem = 'Pacote selecionado nao encontrado.';
                $tipoMensagem = 'erro';
                $reservaEmEdicao = [
                    'id' => $reservaId,
                    'cliente_id' => $clienteId,
                    'pacote_id' => $pacoteId,
                    'status' => $status,
                    'forma_pagamento' => $formaPagamento,
                ];
            } else {
                try {
                    $atualizou = atualizarReserva($pdo, $estruturaReservas, $reservaId, $clienteId, $pacoteId, $status);
                    if ($atualizou) {
                        $erroPagamento = salvarPagamentoReserva($pdo, $reservaId, $formaPagamento);
                        if ($erroPagamento === '') {
                            header('Location: Reservados.php?msg=editado');
                            exit;
                        }
                        $mensagem = 'Reserva atualizada, mas nao foi possivel salvar pagamento. Detalhe: ' . $erroPagamento;
                        $tipoMensagem = 'erro';
                    } else {
                        $mensagem = 'Reserva nao encontrada.';
                        $tipoMensagem = 'erro';
                    }
                } catch (PDOException $e) {
                    $mensagem = 'Nao foi possivel atualizar a reserva.';
                    $tipoMensagem = 'erro';
                    $reservaEmEdicao = [
                        'id' => $reservaId,
                        'cliente_id' => $clienteId,
                        'pacote_id' => $pacoteId,
                        'status' => $status,
                        'forma_pagamento' => $formaPagamento,
                    ];
                }
            }
        } elseif (isset($_POST['excluir_reserva'])) {
            $reservaId = isset($_POST['reserva_id']) ? (int) $_POST['reserva_id'] : 0;

            if ($reservaId <= 0) {
                $mensagem = 'Reserva invalida para exclusao.';
                $tipoMensagem = 'erro';
            } else {
                $resultadoExclusao = excluirReservaComDependencias($pdo, $estruturaReservas, $reservaId);
                if ($resultadoExclusao['ok']) {
                    header('Location: Reservados.php?msg=excluido');
                    exit;
                }
                $mensagem = $resultadoExclusao['mensagem'];
                $tipoMensagem = 'erro';
            }
        }
    }

    if (isset($_GET['msg'])) {
        if ($_GET['msg'] === 'editado') {
            $mensagem = 'Reserva atualizada com sucesso.';
            $tipoMensagem = 'sucesso';
        } elseif ($_GET['msg'] === 'excluido') {
            $mensagem = 'Reserva excluida com sucesso.';
            $tipoMensagem = 'sucesso';
        }
    }

    $editarId = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;
    if ($reservaEmEdicao === null && $editarId > 0) {
        try {
            $reservaEmEdicao = buscarReservaPorId($pdo, $estruturaReservas, $editarId);
            if ($reservaEmEdicao === null) {
                $mensagem = 'Reserva nao encontrada para edicao.';
                $tipoMensagem = 'erro';
            }
        } catch (PDOException $e) {
            $mensagem = 'Nao foi possivel carregar a reserva para edicao.';
            $tipoMensagem = 'erro';
        }
    }

    try {
        $reservas = listarReservas($pdo, $estruturaReservas);
    } catch (PDOException $e) {
        if ($mensagem === '') {
            $mensagem = 'Nao foi possivel carregar as reservas.';
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
    <title>Reservas - Area do admin</title>
    <style>
        * { box-sizing: border-box; font-family: Arial, sans-serif; }
        body { margin: 0; padding: 24px; background: #203a43; color: #fff; }
        .container { max-width: 980px; margin: 0 auto; }
        .topo { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
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
        .lista { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
        .card { background: rgba(255, 255, 255, 0.1); border-radius: 10px; padding: 14px; }
        .card-destaque { outline: 2px solid #f39c12; }
        .card h3 { margin-top: 0; margin-bottom: 6px; }
        .card p { margin: 4px 0; font-size: 0.95rem; }
        .card-id { opacity: 0.75; font-size: 0.85rem; }
        .status { display: inline-block; padding: 3px 8px; border-radius: 999px; background: rgba(255, 255, 255, 0.15); font-size: 0.85rem; }
        .preco { font-weight: bold; margin: 8px 0; }
        .painel-form { background: rgba(255, 255, 255, 0.1); border-radius: 10px; padding: 16px; margin-bottom: 20px; }
        .painel-form h2 { margin-top: 0; font-size: 1.1rem; }
        .painel-form label { display: block; margin-bottom: 4px; font-size: 0.9rem; }
        .painel-form input,
        .painel-form select { width: 100%; max-width: 360px; padding: 8px 10px; margin-bottom: 10px; border: 0; border-radius: 6px; }
        .btn-salvar { background: #2ecc71; color: #fff; font-weight: bold; padding: 10px 14px; border-radius: 8px; border: 0; cursor: pointer; margin-right: 8px; }
        .btn-atualizar { background: #f39c12; color: #fff; font-weight: bold; padding: 10px 14px; border-radius: 8px; border: 0; cursor: pointer; margin-right: 8px; }
        .acoes-card { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .form-inline { display: inline; }
    </style>
</head>
<body>
    <main class="container">
        <div class="topo">
            <h1>Gerenciar reservas</h1>
            <div class="acoes">
                <a class="btn btn-voltar" href="../admin.php">Voltar</a>
                <a class="btn btn-voltar" href="pacotesADM.php">Editar Pacotes</a>
                <a class="btn btn-voltar" href="AdmClientes.php">Ver Clientes</a>
                <a class="btn btn-logout" href="../logout.php">Logout</a>
            </div>
        </div>

        <?php if ($mensagem !== ''): ?>
            <p class="mensagem <?php echo htmlspecialchars($tipoMensagem); ?>">
                <?php echo htmlspecialchars($mensagem); ?>
            </p>
        <?php endif; ?>

        <?php if ($estruturaReservas !== null): ?>
            <?php if ($reservaEmEdicao !== null): ?>
                <section class="painel-form">
                    <h2>Editar reserva #<?php echo (int) $reservaEmEdicao['id']; ?></h2>
                    <form method="POST" action="">
                        <input type="hidden" name="reserva_id" value="<?php echo (int) $reservaEmEdicao['id']; ?>">

                        <label for="edit_cliente_id">Cliente</label>
                        <select id="edit_cliente_id" name="cliente_id" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($clientesOpcoes as $cliente): ?>
                                <option value="<?php echo (int) $cliente['id']; ?>"
                                    <?php echo (int) $reservaEmEdicao['cliente_id'] === (int) $cliente['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cliente['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="edit_pacote_id">Pacote</label>
                        <select id="edit_pacote_id" name="pacote_id" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($pacotesOpcoes as $pacote): ?>
                                <option value="<?php echo (int) $pacote['id']; ?>"
                                    <?php echo (int) $reservaEmEdicao['pacote_id'] === (int) $pacote['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pacote['nome'] . ' - ' . $pacote['destino']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($statusPermitidos as $statusOpcao): ?>
                                <option value="<?php echo htmlspecialchars((string) $statusOpcao['id']); ?>"
                                    <?php echo (string) $reservaEmEdicao['status'] === (string) $statusOpcao['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($statusOpcao['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="edit_forma_pagamento">Forma de pagamento</label>
                        <select id="edit_forma_pagamento" name="forma_pagamento" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($formasPagamento as $forma): ?>
                                <option value="<?php echo htmlspecialchars((string) $forma['id']); ?>"
                                    <?php echo (string) $reservaEmEdicao['forma_pagamento'] === (string) $forma['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($forma['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" name="editar_reserva" class="btn-atualizar">Salvar alteracoes</button>
                        <a class="btn btn-cancelar" href="Reservados.php">Cancelar</a>
                    </form>
                </section>
            <?php else: ?>
                <section class="painel-form">
                    <h2>Adicionar nova reserva</h2>
                    <form method="POST" action="">
                        <label for="cliente_id">Cliente</label>
                        <select id="cliente_id" name="cliente_id" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($clientesOpcoes as $cliente): ?>
                                <option value="<?php echo (int) $cliente['id']; ?>">
                                    <?php echo htmlspecialchars($cliente['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="pacote_id">Pacote</label>
                        <select id="pacote_id" name="pacote_id" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($pacotesOpcoes as $pacote): ?>
                                <option value="<?php echo (int) $pacote['id']; ?>">
                                    <?php echo htmlspecialchars($pacote['nome'] . ' - ' . $pacote['destino']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($statusPermitidos as $statusOpcao): ?>
                                <option value="<?php echo htmlspecialchars((string) $statusOpcao['id']); ?>"
                                    <?php echo (string) $statusOpcao['id'] === 'pendente' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($statusOpcao['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="forma_pagamento">Forma de pagamento</label>
                        <select id="forma_pagamento" name="forma_pagamento" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($formasPagamento as $forma): ?>
                                <option value="<?php echo htmlspecialchars((string) $forma['id']); ?>">
                                    <?php echo htmlspecialchars($forma['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" name="adicionar_reserva" class="btn-salvar">Adicionar reserva</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="lista">
                <?php if (count($reservas) === 0): ?>
                    <article class="card">
                        <h3>Nenhuma reserva encontrada</h3>
                        <p>Use o formulario acima para cadastrar a primeira reserva.</p>
                    </article>
                <?php else: ?>
                    <?php foreach ($reservas as $reserva): ?>
                        <?php $emEdicao = $reservaEmEdicao !== null && (int) $reserva['id'] === (int) $reservaEmEdicao['id']; ?>
                        <article class="card<?php echo $emEdicao ? ' card-destaque' : ''; ?>">
                            <p class="card-id">#<?php echo (int) $reserva['id']; ?></p>
                            <h3><?php echo htmlspecialchars($reserva['nome_cliente']); ?></h3>
                            <p><?php echo htmlspecialchars($reserva['nome_pacote']); ?></p>
                            <?php if ($reserva['destino_pacote'] !== ''): ?>
                                <p><?php echo htmlspecialchars($reserva['destino_pacote']); ?></p>
                            <?php endif; ?>
                            <?php if ($reserva['preco_pacote'] !== null && $reserva['preco_pacote'] !== ''): ?>
                                <p class="preco">R$ <?php echo htmlspecialchars(number_format((float) $reserva['preco_pacote'], 2, ',', '.')); ?></p>
                            <?php endif; ?>
                            <p><span class="status"><?php echo htmlspecialchars(ucfirst($reserva['status'])); ?></span></p>
                            <p>Pagamento: <?php echo htmlspecialchars($reserva['forma_pagamento']); ?></p>
                            <p>Data reserva: <?php echo htmlspecialchars(formatarData($reserva['data_reserva'])); ?></p>
                            <?php if ($reserva['data_pagamento'] !== null && $reserva['data_pagamento'] !== ''): ?>
                                <p>Data pagamento: <?php echo htmlspecialchars(formatarData($reserva['data_pagamento'])); ?></p>
                            <?php endif; ?>
                            <div class="acoes-card">
                                <a class="btn btn-editar" href="Reservados.php?editar=<?php echo (int) $reserva['id']; ?>">Editar</a>
                                <form class="form-inline" method="POST" action="Reservados.php" onsubmit="return confirm('Deseja realmente excluir esta reserva? O pagamento vinculado tambem sera removido.');">
                                    <input type="hidden" name="reserva_id" value="<?php echo (int) $reserva['id']; ?>">
                                    <button type="submit" name="excluir_reserva" class="btn btn-deletar">Deletar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
