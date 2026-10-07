<?php

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';
require_once 'csrf.php';

$usuario_id = (int) $_SESSION['usuario_id'];
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? '';
$inicial = strtoupper(substr(trim($nombre), 0, 1));
$error = '';
$mensaje = '';
$modo_edicion = false;
$usuario_editar = null;
$equipos = [];
$coordinadores = [];

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
    $stmt = $pdo->prepare(
        'SELECT u.id, u.nombre, u.usuario, u.rol, u.estado,
                eu.equipo_id, e.lider_id
         FROM usuarios u
         LEFT JOIN equipo_usuarios eu ON eu.usuario_id = u.id
         LEFT JOIN equipos e ON e.id = eu.equipo_id
         WHERE u.id = :id'
    );
    $stmt->execute([':id' => $id_editar]);
    $usuario_editar = $stmt->fetch();

    if ($usuario_editar) {
        $modo_edicion = true;
    } else {
        $error = 'El usuario seleccionado no existe.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
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
        $equipo_formulario = filter_var($_POST['equipo_id'] ?? 0, FILTER_VALIDATE_INT) ?: null;
        $lider_formulario = filter_var($_POST['lider_id'] ?? 0, FILTER_VALIDATE_INT) ?: null;
        $estado_formulario = isset($_POST['estado']) ? 1 : 0;

        if ($nombre_formulario === '' || $usuario_formulario === '') {
            $error = 'Debe ingresar el nombre y el usuario.';
        } elseif (!in_array($rol_formulario, ['ADMIN', 'COORDINADOR', 'INVESTIGADOR'], true)) {
            $error = 'Debe seleccionar un perfil válido.';
        } elseif ($rol_formulario !== 'ADMIN' && !$equipo_formulario) {
            $error = 'Debe seleccionar un equipo.';
        } elseif ($rol_formulario !== 'ADMIN' && $equipo_formulario) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM equipo_usuarios
                 WHERE equipo_id = :equipo_id AND usuario_id <> :usuario_id'
            );
            $stmt->execute([
                ':equipo_id' => $equipo_formulario,
                ':usuario_id' => $id ?: 0
            ]);
            if ((int) $stmt->fetchColumn() >= 2) {
                $error = 'Cada equipo debe tener exactamente dos integrantes.';
            }
        }

        if ($error === '' && !$id && strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif ($error === '' && $id && $password !== '' && strlen($password) < 6) {
            $error = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } elseif ($error === '' && $id === $usuario_id && $estado_formulario === 0) {
            $error = 'No puede desactivar su propio usuario.';
        }

        if ($error === '' && $rol_formulario === 'COORDINADOR' && $equipo_formulario) {
            $stmt = $pdo->prepare(
                "SELECT u.id FROM usuarios u
                 INNER JOIN equipo_usuarios eu ON eu.usuario_id = u.id
                 WHERE eu.equipo_id = :equipo_id AND u.rol = 'COORDINADOR'
                   AND u.id <> :usuario_id LIMIT 1"
            );
            $stmt->execute([
                ':equipo_id' => $equipo_formulario,
                ':usuario_id' => $id ?: 0
            ]);
            if ($stmt->fetchColumn()) {
                $error = 'Ese equipo ya tiene un coordinador. Debe quitarlo del equipo antes de asignar otro.';
            }
        }

        if ($error === '' && $lider_formulario && $equipo_formulario) {
            $stmt = $pdo->prepare(
                "SELECT u.id FROM usuarios u
                 INNER JOIN equipo_usuarios eu ON eu.usuario_id = u.id
                 WHERE eu.equipo_id = :equipo_id AND eu.usuario_id = :lider_id
                   AND u.rol = 'COORDINADOR' AND u.estado = 1"
            );
            $stmt->execute([
                ':equipo_id' => $equipo_formulario,
                ':lider_id' => $lider_formulario
            ]);
            if (!$stmt->fetchColumn()) {
                $error = 'El líder seleccionado debe ser un coordinador activo del mismo equipo.';
            }
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
                    $usuario_guardado = $id;
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
                    $usuario_guardado = (int) $pdo->lastInsertId();
                    $mensaje = 'Usuario creado correctamente.';
                }

                if ($equipo_formulario) {
                    $stmt = $pdo->prepare('UPDATE equipos SET lider_id = NULL WHERE lider_id = :usuario_id');
                    $stmt->execute([':usuario_id' => $usuario_guardado]);
                    $stmt = $pdo->prepare('DELETE FROM equipo_usuarios WHERE usuario_id = :usuario_id');
                    $stmt->execute([':usuario_id' => $usuario_guardado]);
                    $stmt = $pdo->prepare('INSERT INTO equipo_usuarios (equipo_id, usuario_id) VALUES (:equipo_id, :usuario_id)');
                    $stmt->execute([':equipo_id' => $equipo_formulario, ':usuario_id' => $usuario_guardado]);
                    if ($rol_formulario === 'COORDINADOR') {
                        $stmt = $pdo->prepare('UPDATE equipos SET lider_id = :lider_id WHERE id = :equipo_id');
                        $stmt->execute([':lider_id' => $usuario_guardado, ':equipo_id' => $equipo_formulario]);
                    } elseif ($lider_formulario) {
                        $stmt = $pdo->prepare('UPDATE equipos SET lider_id = :lider_id WHERE id = :equipo_id');
                        $stmt->execute([':lider_id' => $lider_formulario, ':equipo_id' => $equipo_formulario]);
                    }
                }

                $modo_edicion = false;
                $usuario_editar = null;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'El nombre de usuario ya está registrado.';
                } else {
                    error_log('NEXO - error al guardar usuario: ' . $e->getMessage());
                    $error = 'No fue posible guardar el usuario. Intente de nuevo o contacte al administrador.';
                }
            }
        }
    }
}

if (isset($_GET['actualizado'])) {
    $mensaje = 'Estado del usuario actualizado correctamente.';
}

$stmt = $pdo->query(
    'SELECT u.id, u.nombre, u.usuario, u.rol, u.estado, u.fecha_creacion,
            e.nombre AS equipo, lider.nombre AS lider
     FROM usuarios u
     LEFT JOIN equipo_usuarios eu ON eu.usuario_id = u.id
     LEFT JOIN equipos e ON e.id = eu.equipo_id
     LEFT JOIN usuarios lider ON lider.id = e.lider_id
     ORDER BY u.nombre ASC'
);
$usuarios = $stmt->fetchAll();
$equipos = $pdo->query('SELECT id, nombre, tipo, lider_id FROM equipos WHERE estado = 1 ORDER BY id')->fetchAll();
$coordinadores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'COORDINADOR' AND estado = 1 ORDER BY nombre")->fetchAll();

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
                <div class="usuario-info"><strong><?= e($nombre) ?></strong><small>Administrador</small><small class="usuario-equipo">Equipo: <?= e($_SESSION['equipo_nombre'] ?? 'Sin equipo asignado') ?></small></div>
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
                    <?= csrf_campo() ?>
                    <?php if ($modo_edicion): ?><input type="hidden" name="id" value="<?= (int) $usuario_editar['id'] ?>">
                    <?php endif; ?>
                    <div class="campo"><label for="nombre">Nombre completo</label><input type="text" id="nombre" name="nombre" value="<?= e($usuario_editar['nombre'] ?? '') ?>" required></div>
                    <div class="campo"><label for="usuario">Nombre de usuario</label><input type="text" id="usuario" name="usuario" value="<?= e($usuario_editar['usuario'] ?? '') ?>" autocomplete="off" required></div>
                    <div class="campo"><label for="rol">Perfil</label><select id="rol" name="rol" required><option value="">Seleccione...</option><option value="INVESTIGADOR" <?= ($usuario_editar['rol'] ?? '') === 'INVESTIGADOR' ? 'selected' : '' ?>>Investigador</option><option value="COORDINADOR" <?= ($usuario_editar['rol'] ?? '') === 'COORDINADOR' ? 'selected' : '' ?>>Coordinador</option><option value="ADMIN" <?= ($usuario_editar['rol'] ?? '') === 'ADMIN' ? 'selected' : '' ?>>Administrador</option></select></div>
                    <div class="campo"><label for="equipo_id">Equipo</label><select id="equipo_id" name="equipo_id"><option value="">Sin equipo</option><?php foreach ($equipos as $equipo): ?><option value="<?= (int) $equipo['id'] ?>" <?= (int) ($usuario_editar['equipo_id'] ?? 0) === (int) $equipo['id'] ? 'selected' : '' ?>><?= e($equipo['nombre']) ?><?= $equipo['tipo'] === 'ADMINISTRATIVO' ? ' (administrativo)' : '' ?></option><?php endforeach; ?></select><small>El Equipo 4 es administrativo y no recibe asignaciones de capitales.</small></div>
                    <div class="campo"><label for="lider_id">Líder del equipo</label><select id="lider_id" name="lider_id"><option value="">Sin líder definido</option><?php foreach ($coordinadores as $coordinador): ?><option value="<?= (int) $coordinador['id'] ?>" <?= (int) ($usuario_editar['lider_id'] ?? 0) === (int) $coordinador['id'] ? 'selected' : '' ?>><?= e($coordinador['nombre']) ?></option><?php endforeach; ?></select><small>El líder debe tener perfil Coordinador.</small></div>
                    <div class="campo"><label for="password">Contraseña <?= $modo_edicion ? '(opcional)' : '' ?></label><input type="password" id="password" name="password" autocomplete="new-password" <?= $modo_edicion ? '' : 'required' ?>><small><?= $modo_edicion ? 'Déjela vacía para conservar la actual.' : 'Mínimo 6 caracteres.' ?></small></div>
                    <label class="usuario-estado"><input type="checkbox" name="estado" value="1" <?= !$modo_edicion || (int) $usuario_editar['estado'] === 1 ? 'checked' : '' ?>> Usuario activo</label>
                    <div class="form-acciones"><a href="usuarios.php" class="btn-cancelar">Cancelar</a><button type="submit" class="btn-principal"><?= $modo_edicion ? 'Guardar cambios' : 'Crear usuario' ?></button></div>
                </form>
            </section>

            <section class="panel panel-visitas usuarios-listado">
                <div class="panel-header"><div><h2>Usuarios registrados</h2><p><?= count($usuarios) ?> cuentas en el sistema</p></div></div>
                <div class="tabla-contenedor">
                    <table class="tabla-visitas"><thead><tr><th>Usuario</th><th>Perfil</th><th>Equipo</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
                    <?php foreach ($usuarios as $usuario): ?>
                        <tr>
                            <td><div class="responsable"><span class="mini-avatar"><?= e(strtoupper(substr(trim($usuario['nombre']), 0, 1))) ?></span><div class="institucion"><strong><?= e($usuario['nombre']) ?></strong><small>@<?= e($usuario['usuario']) ?></small></div></div></td>
                            <td><span class="badge-rol <?= $usuario['rol'] === 'ADMIN' ? 'badge-rol-admin' : '' ?>"><?= $usuario['rol'] === 'ADMIN' ? 'Administrador' : ($usuario['rol'] === 'COORDINADOR' ? 'Coordinador' : ($usuario['rol'] === 'INVESTIGADOR' ? 'Investigador' : 'Sin perfil')) ?></span></td>
                            <td><div class="institucion"><strong><?= e($usuario['equipo'] ?? 'Sin equipo') ?></strong><small><?= e($usuario['lider'] ? 'Líder: ' . $usuario['lider'] : '') ?></small></div></td>
                            <td><span class="badge-usuario-estado <?= (int) $usuario['estado'] === 1 ? 'activo' : 'inactivo' ?>"><?= (int) $usuario['estado'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                            <td><div class="acciones-tabla"><a href="usuarios.php?editar=<?= (int) $usuario['id'] ?>" class="btn-tabla btn-editar">Editar</a><?php if ((int) $usuario['id'] !== $usuario_id): ?><form method="POST" class="form-estado"><?= csrf_campo() ?><input type="hidden" name="accion" value="cambiar_estado"><input type="hidden" name="id" value="<?= (int) $usuario['id'] ?>"><button type="submit" class="btn-tabla <?= (int) $usuario['estado'] === 1 ? 'btn-desactivar' : 'btn-activar' ?>"><?= (int) $usuario['estado'] === 1 ? 'Desactivar' : 'Activar' ?></button></form><?php endif; ?></div></td>
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
