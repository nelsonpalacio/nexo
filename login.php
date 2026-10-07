<?php

session_start();
require_once "config.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $usuario = trim($_POST["usuario"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($usuario === "" || $password === "") {
        $error = "Debe ingresar usuario y contraseña.";
    } else {

        $sql = "SELECT * FROM usuarios 
                WHERE usuario = :usuario 
                AND estado = 1
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":usuario" => $usuario
        ]);

        $usuarioDB = $stmt->fetch();

        if ($usuarioDB && password_verify($password, $usuarioDB["password"])) {

            $_SESSION["usuario_id"] = $usuarioDB["id"];
            $_SESSION["nombre"] = $usuarioDB["nombre"];
            $_SESSION["rol"] = $usuarioDB["rol"];

            $stmtEquipo = $pdo->prepare(
                "SELECT e.nombre
                 FROM equipo_usuarios eu
                 INNER JOIN equipos e ON e.id = eu.equipo_id
                 WHERE eu.usuario_id = :usuario_id AND e.estado = 1
                 LIMIT 1"
            );
            $stmtEquipo->execute([":usuario_id" => $usuarioDB["id"]]);
            $_SESSION["equipo_nombre"] = $stmtEquipo->fetchColumn() ?: 'Sin equipo asignado';

            header("Location: index.php");
            exit;

        } else {
            $error = "Usuario o contraseña incorrectos.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Iniciar sesión | Levantamiento NEXO</title>

    <link rel="stylesheet" href="css/estilos.css">
</head>

<body>

<div class="login-container">

    <div class="login-card">

        <img
            src="logo.png"
            alt="NEXO - Conectando información, conocimiento y gestión"
            class="logo-login"
        >

        <p class="login-subtitle">
            Sistema de levantamiento de información
        </p>

        <?php if ($error): ?>
            <div class="alerta">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="campo">
                <label>Usuario</label>

                <input
                    type="text"
                    name="usuario"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="campo">
                <label>Contraseña</label>

                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="btn-login">
                Iniciar sesión
            </button>

        </form>

    </div>

</div>

</body>
</html>