<?php

require 'security.php';

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Usuario</title>
    <link rel="stylesheet" href="styles1.css">
</head>
<body>
    <main class="container">
        <h1>Bem-vindo, <?php echo htmlspecialchars($_SESSION['usuario_nome']); ?>!</h1>
        <p>Escolha uma opcao para continuar.</p>

        <div class="acoes">
            <a class="btn btn-reserva" href="AdminPacotes/pacotesADM.php">Editar Pacotes</a>
            <a class="btn btn-reserva" href="AdminPacotes/AdmClientes.php">Ver Clientes</a>
            <a class="btn btn-logout" href="../logout.php">Logout</a>
        </div>
    </main>
</body>
</html>
