<?php

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

$usuario_id = (int) $_SESSION['usuario_id'];
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? '';
$inicial = strtoupper(substr(trim($nombre), 0, 1));
$error = '';
$mensaje = '';
$modo_edicion = false;
$usuario_editar = null;

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

if ($rol !== 'ADMIN') {
    http_response_code(403);
    exit('No tiene permisos para administrar usuarios.');
}

$id_editar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);

if ($id_editar) {
    $stmt = $pdo->prepare('SELECT id, nombre, usuario, rol, estado FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id_editar]);
    $usuario_editar = $stmt->fetch();

    if ($usuario_editar) {
        $modo_edicion = true;
    } else {
        $error = 'El usuario seleccionado no existe.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);

    if ($accion === 'cambiar_estado') {
        if (!$id || $id === $usuario_id) {
            $error = 'No puede desactivar su propio usuario desde esta pantalla.';
        } else {
            $stmt = $pdo->prepare('UPDATE usuarios SET estado = IF(estado = 1, 0, 1) WHERE id = :id');
            $stmt->execute([':id' => $id]);
            header('Location: usuarios.php?actualizado=1');
            exit;
        }
    } elseif ($accion === 'guardar') {
        $nombre_formulario = trim($_POST['nombre'] ?? '');
        $usuario_formulario = trim($_POST['usuario'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol_formulario = $_POST['rol'] ?? '';
        $estado_formulario = isset($_POST['estado']) ? 1 : 0;

        if ($nombre_formulario === '' || $usuario_formulario === '') {
            $error = 'Debe ingresar el nombre y el usuario.';
        } elseif (!in_array($rol_formulario, ['ADMIN', 'INVESTIGADOR'], true)) {
            $error = 'Debe seleccionar un perfil válido.';
        } elseif (!$id && strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif ($id && $password !== '' && strlen($password) < 6) {
            $error = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } elseif ($id === $usuario_id && $estado_formulario === 0) {
            $error = 'No puede desactivar su propio usuario.';
        }

        if ($error === '') {
            try {
                if ($id) {
                    $sql = 'UPDATE usuarios SET nombre = :nombre, usuario = :usuario,
                            rol = :rol, estado = :estado';
                    $parametros = [
                        ':nombre' => $nombre_formulario,
                        ':usuario' => $usuario_formulario,
                        ':rol' => $rol_formulario,
                        ':estado' => $estado_formulario,
                        ':id' => $id
                    ];

                    if ($password !== '') {
                        $sql .= ', password = :password';
                        $parametros[':password'] = password_hash($password, PASSWORD_DEFAULT);
                    }

                    $sql .= ' WHERE id = :id';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($parametros);
                    $mensaje = 'Usuario actualizado correctamente.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO usuarios (nombre, usuario, password, rol, estado)
                         VALUES (:nombre, :usuario, :password, :rol, :estado)'
                    );
                    $stmt->execute([
                        ':nombre' => $nombre_formulario,
                        ':usuario' => $usuario_formulario,
                        ':password' => password_hash($password, PASSWORD_DEFAULT),
                        ':rol' => $rol_formulario,
                        ':estado' => $estado_formulario
                    ]);
                    $mensaje = 'Usuario creado correctamente.';
                }

                $modo_edicion = false;
                $usuario_editar = null;
            } catch (PDOException $e) {
                $error = $e->getCode() === '23000'
                    ? 'El nombre de usuario ya está registrado.'
                    : 'No fue posible guardar el usuario. ' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['actualizado'])) {
    $mensaje = 'Estado del usuario actualizado correctamente.';
}

$stmt = $pdo->query(
    'SELECT id, nombre, usuario, rol, estado, fecha_creacion
     FROM usuarios ORDER BY nombre ASC'
);
$usuarios = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios | NEXO</title>
    <link rel="stylesheet" href="css/estilos.css">
    <link rel="stylesheet" href="css/visitas.css">
</head>
<body>
<div class="app">
    <button class="menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <div class="menu-overlay"></div>
    <aside class="sidebar">
        <div class="logo"><img src="logo2.png" alt="NEXO" class="logo-sidebar"></div>
        <nav class="menu">
            <a href="index.php" class="menu-item"><span class="icono">⌂</span><span>Inicio</span></a>
            <a href="visitas/visitas.php" class="menu-item"><span class="icono">▣</span><span>Visitas</span></a>
            <a href="evidencias/evidencias.php" class="menu-item"><span class="icono">▧</span><span>Evidencias fotográficas</span></a>
            <a href="archivos/archivos.php" class="menu-item"><span class="icono">□</span><span>Archivos</span></a>
            <a href="reportes.php" class="menu-item"><span class="icono">▥</span><span>Reportes</span></a>
            <div class="menu-separador"></div>
            <a href="usuarios.php" class="menu-item activo"><span class="icono">♙</span><span>Usuarios</span></a>
        </nav>
        <div class="sidebar-footer">
            <div class="usuario">
                <div class="usuario-avatar"><?= e($inicial) ?></div>
                <div class="usuario-info"><strong><?= e($nombre) ?></strong><small>Administrador</small></div>
            </div>
            <a href="logout.php" class="cerrar-sesion"><span>↪</span>Cerrar sesión</a>
        </div>
    </aside>

    <main class="contenido">
        <header class="header">
            <div>
                <div class="breadcrumb">NEXO / Usuarios</div>
                <h1>Usuarios</h1>
                <p>Administre las cuentas y los perfiles de acceso al sistema.</p>
            </div>
            <div class="fecha"><?= date('d/m/Y') ?></div>
        </header>

        <?php if ($error !== ''): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($mensaje !== ''): ?><div class="alerta alerta-exito"><?= e($mensaje) ?></div><?php endif; ?>

        <section class="usuarios-layout">
            <section class="panel usuario-formulario">
                <div class="panel-header">
                    <div>
                        <h2><?= $modo_edicion ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
                        <p><?= $modo_edicion ? 'Actualice sus datos y perfil.' : 'Cree una cuenta con el perfil correspondiente.' ?></p>
                    </div>
                </div>

                <form method="POST" action="usuarios.php<?= $modo_edicion ? '?editar=' . (int) $usuario_editar['id'] : '' ?>">
                    <input type="hidden" name="accion" value="guardar">
                    <?php if ($modo_edicion): ?><input type="hidden" name="id" value="<?= (int) $usuario_editar['id'] ?>">
                    <?php endif; ?>
                    <div class="campo"><label for="nombre">Nombre completo</label><input type="text" id="nombre" name="nombre" value="<?= e($usuario_editar['nombre'] ?? '') ?>" required></div>
                    <div class="campo"><label for="usuario">Nombre de usuario</label><input type="text" id="usuario" name="usuario" value="<?= e($usuario_editar['usuario'] ?? '') ?>" autocomplete="off" required></div>
                    <div class="campo"><label for="rol">Perfil</label><select id="rol" name="rol" required><option value="">Seleccione...</option><option value="INVESTIGADOR" <?= ($usuario_editar['rol'] ?? '') === 'INVESTIGADOR' ? 'selected' : '' ?>>Investigador</option><option value="ADMIN" <?= ($usuario_editar['rol'] ?? '') === 'ADMIN' ? 'selected' : '' ?>>Administrador</option></select></div>
                    <div class="campo"><label for="password">Contraseña <?= $modo_edicion ? '(opcional)' : '' ?></label><input type="password" id="password" name="password" autocomplete="new-password" <?= $modo_edicion ? '' : 'required' ?>><small><?= $modo_edicion ? 'Déjela vacía para conservar la actual.' : 'Mínimo 6 caracteres.' ?></small></div>
                    <label class="usuario-estado"><input type="checkbox" name="estado" value="1" <?= !$modo_edicion || (int) $usuario_editar['estado'] === 1 ? 'checked' : '' ?>> Usuario activo</label>
                    <div class="form-acciones"><a href="usuarios.php" class="btn-cancelar">Cancelar</a><button type="submit" class="btn-principal"><?= $modo_edicion ? 'Guardar cambios' : 'Crear usuario' ?></button></div>
                </form>
            </section>

            <section class="panel panel-visitas usuarios-listado">
                <div class="panel-header"><div><h2>Usuarios registrados</h2><p><?= count($usuarios) ?> cuentas en el sistema</p></div></div>
                <div class="tabla-contenedor">
                    <table class="tabla-visitas"><thead><tr><th>Usuario</th><th>Perfil</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
                    <?php foreach ($usuarios as $usuario): ?>
                        <tr>
                            <td><div class="responsable"><span class="mini-avatar"><?= e(strtoupper(substr(trim($usuario['nombre']), 0, 1))) ?></span><div class="institucion"><strong><?= e($usuario['nombre']) ?></strong><small>@<?= e($usuario['usuario']) ?></small></div></div></td>
                            <td><span class="badge-rol <?= $usuario['rol'] === 'ADMIN' ? 'badge-rol-admin' : '' ?>"><?= $usuario['rol'] === 'ADMIN' ? 'Administrador' : ($usuario['rol'] === 'INVESTIGADOR' ? 'Investigador' : 'Sin perfil') ?></span></td>
                            <td><span class="badge-usuario-estado <?= (int) $usuario['estado'] === 1 ? 'activo' : 'inactivo' ?>"><?= (int) $usuario['estado'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                            <td><div class="acciones-tabla"><a href="usuarios.php?editar=<?= (int) $usuario['id'] ?>" class="btn-tabla btn-editar">Editar</a><?php if ((int) $usuario['id'] !== $usuario_id): ?><form method="POST" class="form-estado"><input type="hidden" name="accion" value="cambiar_estado"><input type="hidden" name="id" value="<?= (int) $usuario['id'] ?>"><button type="submit" class="btn-tabla <?= (int) $usuario['estado'] === 1 ? 'btn-desactivar' : 'btn-activar' ?>"><?= (int) $usuario['estado'] === 1 ? 'Desactivar' : 'Activar' ?></button></form><?php endif; ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
            </section>
        </section>
    </main>
</div>
<script>
    (function () {
        var app = document.querySelector('.app'); var toggle = document.querySelector('.menu-toggle'); var overlay = document.querySelector('.menu-overlay');
        if (!app || !toggle || !overlay) return;
        function cerrarMenu() { app.classList.remove('menu-abierto'); toggle.setAttribute('aria-expanded', 'false'); }
        toggle.addEventListener('click', function () { var abierto = app.classList.toggle('menu-abierto'); toggle.setAttribute('aria-expanded', abierto ? 'true' : 'false'); }); overlay.addEventListener('click', cerrarMenu);
        app.querySelectorAll('.menu-item').forEach(function (enlace) { enlace.addEventListener('click', cerrarMenu); });
    }());
</script>
</body>
</html>
