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
$buscar = trim($_GET['buscar'] ?? '');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$por_pagina = 10;
$equipo_post = 0;
$ver_todos = $rol === 'ADMIN' && isset($_GET['todos']) && $_GET['todos'] === '1';

function e(?string $valor): string { return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8'); }

$equipo_coordinador = null;
if ($rol !== 'ADMIN') {
    $stmt = $pdo->prepare("SELECT id, nombre, tipo FROM equipos WHERE lider_id = :lider_id AND estado = 1 LIMIT 1");
    if ($rol !== 'COORDINADOR') {
        $stmt = $pdo->prepare("SELECT e.id, e.nombre, e.tipo FROM equipo_usuarios eu INNER JOIN equipos e ON e.id = eu.equipo_id WHERE eu.usuario_id = :usuario_id AND e.estado = 1 LIMIT 1");
        $stmt->execute([':usuario_id' => $usuario_id]);
    } else {
        $stmt->execute([':lider_id' => $usuario_id]);
    }
    $equipo_coordinador = $stmt->fetch();
    if (!$equipo_coordinador) exit('No tiene un equipo operativo asignado.');
}

if ($rol === 'ADMIN' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $accion = $_POST['accion'] ?? 'guardar';
    $asignacion_id = (int) ($_POST['asignacion_id'] ?? 0);
    $equipo_id = $rol === 'COORDINADOR' ? (int) $equipo_coordinador['id'] : (int) ($_POST['equipo_id'] ?? 0);
    $equipo_post = $equipo_id;
    $capital_id = (int) ($_POST['capital_id'] ?? 0);

    if ($accion === 'eliminar' && $asignacion_id) {
        $condicion = $rol === 'COORDINADOR'
            ? 'id = :id AND equipo_id = :equipo_id'
            : 'id = :id';
        $stmt = $pdo->prepare("DELETE FROM asignaciones_capitales WHERE $condicion");
        $parametros = [':id' => $asignacion_id];
        if ($rol === 'COORDINADOR') $parametros[':equipo_id'] = $equipo_id;
        $stmt->execute($parametros);
        $mensaje = $stmt->rowCount() ? 'Estado eliminado del equipo.' : 'La asignación no existe o no tiene permisos.';
    } else {

    $stmt = $pdo->prepare("SELECT id, tipo FROM equipos WHERE id = :id AND estado = 1");
    $stmt->execute([':id' => $equipo_id]);
    $equipo = $stmt->fetch();

    if (!$equipo || $equipo['tipo'] !== 'OPERATIVO') {
        $error = 'El equipo administrativo no puede recibir asignaciones de capitales.';
    } elseif (!$capital_id) {
        $error = 'Debe seleccionar una capital.';
    } else {
        try {
            $sql_duplicado = 'SELECT COUNT(*) FROM asignaciones_capitales WHERE capital_id = :capital_id';
            if ($accion === 'editar') $sql_duplicado .= ' AND id <> :asignacion_id';
            $stmt = $pdo->prepare($sql_duplicado);
            $parametros_duplicado = [':capital_id' => $capital_id];
            if ($accion === 'editar') $parametros_duplicado[':asignacion_id'] = $asignacion_id;
            $stmt->execute($parametros_duplicado);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('Ese estado ya está asignado a un equipo. Seleccione otro estado disponible.');
            }

            $stmt = $pdo->prepare('SELECT lider_id FROM equipos WHERE id = :equipo_id');
            $stmt->execute([':equipo_id' => $equipo_id]);
            $lider_id = (int) $stmt->fetchColumn();

            if ($accion === 'editar' && $asignacion_id) {
                $condicion = $rol === 'COORDINADOR' ? 'id = :id AND equipo_id = :equipo_id' : 'id = :id';
                $stmt = $pdo->prepare("UPDATE asignaciones_capitales SET capital_id = :capital_id, equipo_id = :equipo_id, usuario_id = :usuario_id WHERE $condicion");
                $parametros = [':capital_id' => $capital_id, ':equipo_id' => $equipo_id, ':usuario_id' => $lider_id, ':id' => $asignacion_id];
                if ($rol === 'COORDINADOR') $parametros[':equipo_id'] = $equipo_id;
                $stmt->execute($parametros);
                $mensaje = 'Asignación actualizada correctamente.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO asignaciones_capitales (capital_id, usuario_id, equipo_id, asignado_por) VALUES (:capital_id, :usuario_id, :equipo_id, :asignado_por)');
                $stmt->execute([':capital_id' => $capital_id, ':usuario_id' => $lider_id, ':equipo_id' => $equipo_id, ':asignado_por' => $usuario_id]);
                $mensaje = 'Estado asignado correctamente.';
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = 'No fue posible asignar la capital.';
        }
    }
    }
}

$equipos = $rol === 'ADMIN'
    ? $pdo->query('SELECT id, nombre, tipo FROM equipos WHERE estado = 1 ORDER BY id')->fetchAll()
    : [$equipo_coordinador];

$asignacion_editar = null;
$id_editar = (int) ($_GET['editar'] ?? 0);
if ($id_editar) {
    $condicion_editar = $rol === 'COORDINADOR'
        ? 'id = :id AND equipo_id = :equipo_id'
        : 'id = :id';
    $stmt = $pdo->prepare("SELECT id, capital_id, equipo_id FROM asignaciones_capitales WHERE $condicion_editar");
    $parametros_editar = [':id' => $id_editar];
    if ($rol === 'COORDINADOR') $parametros_editar[':equipo_id'] = $equipo_coordinador['id'];
    $stmt->execute($parametros_editar);
    $asignacion_editar = $stmt->fetch();
}

$capitales = $pdo->query(
    'SELECT c.id, c.estado, c.capital, c.gobernacion
     FROM capitales c
     WHERE c.estado_activo = 1
       AND NOT EXISTS (
           SELECT 1 FROM asignaciones_capitales ac
           WHERE ac.capital_id = c.id
             AND ac.id <> ' . ($asignacion_editar ? (int) $asignacion_editar['id'] : 0) . '
       )
     ORDER BY c.estado'
)->fetchAll();


$equipo_seleccionado = $rol !== 'ADMIN'
    ? (int) $equipo_coordinador['id']
    : ($equipo_post ?: (int) ($_GET['equipo_id'] ?? ($equipos[0]['id'] ?? 0)));

if ($rol === 'ADMIN' && !$ver_todos && !$equipo_post && !isset($_GET['equipo_id'])) {
    $stmt = $pdo->query(
        'SELECT equipo_id FROM asignaciones_capitales
         ORDER BY fecha_asignacion DESC, id DESC LIMIT 1'
    );
    $equipo_con_asignacion = (int) $stmt->fetchColumn();
    if ($equipo_con_asignacion > 0) {
        $equipo_seleccionado = $equipo_con_asignacion;
    }
}

$pagina = $equipo_post ? 1 : $pagina;
$filtro_asignaciones = ($ver_todos ? '1 = 1' : 'ac.equipo_id = :equipo_id') . "
       AND (c.estado LIKE :buscar_estado
            OR c.capital LIKE :buscar_capital
            OR c.gobernacion LIKE :buscar_gobernacion
          OR lider.nombre LIKE :buscar_lider
          OR e.nombre LIKE :buscar_equipo)";

$stmt = $pdo->prepare(
        "SELECT ac.id, ac.equipo_id, c.estado, c.capital, c.gobernacion,
            e.nombre AS equipo, lider.nombre AS lider,
            COUNT(DISTINCT v.id) AS visitas
     FROM asignaciones_capitales ac
     INNER JOIN capitales c ON c.id = ac.capital_id
     INNER JOIN equipos e ON e.id = ac.equipo_id
    LEFT JOIN usuarios lider ON lider.id = e.lider_id
    LEFT JOIN visitas v ON v.estado_venezuela = c.estado
    WHERE $filtro_asignaciones
    GROUP BY ac.id, ac.equipo_id, c.estado, c.capital, c.gobernacion, e.nombre, lider.nombre
    ORDER BY c.estado
    LIMIT $por_pagina OFFSET " . (($pagina - 1) * $por_pagina)
);
$parametro_busqueda = '%' . $buscar . '%';
$parametros_asignaciones = [
    ':buscar_estado' => $parametro_busqueda,
    ':buscar_capital' => $parametro_busqueda,
    ':buscar_gobernacion' => $parametro_busqueda,
    ':buscar_lider' => $parametro_busqueda,
    ':buscar_equipo' => $parametro_busqueda
];
if (!$ver_todos) $parametros_asignaciones[':equipo_id'] = $equipo_seleccionado;
$stmt->execute($parametros_asignaciones);
$asignaciones = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM asignaciones_capitales ac
     INNER JOIN capitales c ON c.id = ac.capital_id
     INNER JOIN equipos e ON e.id = ac.equipo_id
     LEFT JOIN usuarios lider ON lider.id = e.lider_id
     WHERE $filtro_asignaciones"
);
$parametros_asignaciones = [
    ':buscar_estado' => $parametro_busqueda,
    ':buscar_capital' => $parametro_busqueda,
    ':buscar_gobernacion' => $parametro_busqueda,
    ':buscar_lider' => $parametro_busqueda,
    ':buscar_equipo' => $parametro_busqueda
];
if (!$ver_todos) $parametros_asignaciones[':equipo_id'] = $equipo_seleccionado;
$stmt->execute($parametros_asignaciones);
$total_asignaciones = (int) $stmt->fetchColumn();
$total_paginas = max(1, (int) ceil($total_asignaciones / $por_pagina));
if ($pagina > $total_paginas) {
    $parametros_redireccion = array_filter([
        'equipo_id' => (!$ver_todos && $rol === 'ADMIN') ? $equipo_seleccionado : null,
        'todos' => $ver_todos ? 1 : null,
        'buscar' => $buscar ?: null,
        'pagina' => $total_paginas
    ]);
    header('Location: equipos.php?' . http_build_query($parametros_redireccion));
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Equipos y capitales | NEXO</title><link rel="stylesheet" href="css/estilos.css"><link rel="stylesheet" href="css/visitas.css"><?php if ($rol !== 'ADMIN'): ?><style>.usuario-formulario,.acciones-tabla,a[href="usuarios.php"]{display:none!important}.usuarios-listado{grid-column:1 / -1!important}.usuarios-listado table th:last-child{display:none!important}</style><?php endif; ?>
</head>
<body><div class="app">
<button class="menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false"><span></span><span></span><span></span></button><div class="menu-overlay"></div>
<aside class="sidebar"><div class="logo"><img src="logo2.png" alt="NEXO" class="logo-sidebar"></div><nav class="menu">
<a href="index.php" class="menu-item"><span class="icono">⌂</span><span>Inicio</span></a><a href="visitas/visitas.php" class="menu-item"><span class="icono">▣</span><span>Visitas</span></a><a href="evidencias/evidencias.php" class="menu-item"><span class="icono">▧</span><span>Evidencias fotográficas</span></a><a href="archivos/archivos.php" class="menu-item"><span class="icono">□</span><span>Archivos</span></a><a href="reportes.php" class="menu-item"><span class="icono">▥</span><span>Reportes</span></a><div class="menu-separador"></div><a href="usuarios.php" class="menu-item"><span class="icono">♙</span><span>Usuarios</span></a><a href="equipos.php" class="menu-item activo"><span class="icono">◎</span><span>Equipos y capitales</span></a><a href="cobertura.php" class="menu-item"><span class="icono">◉</span><span>Cobertura territorial</span></a>
</nav><div class="sidebar-footer"><div class="usuario"><div class="usuario-avatar"><?= e($inicial) ?></div><div class="usuario-info"><strong><?= e($nombre) ?></strong><small><?= $rol === 'ADMIN' ? 'Administrador' : 'Coordinador' ?></small></div></div><a href="logout.php" class="cerrar-sesion"><span>↪</span>Cerrar sesión</a></div></aside>
<main class="contenido"><header class="header"><div><div class="breadcrumb">NEXO / Equipos y estados</div><h1>Equipos y estados</h1><p>Asigne cada estado al equipo completo. Sus dos integrantes trabajarán juntos.</p></div><div class="fecha"><?= date('d/m/Y') ?></div></header>
<?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?><?php if ($mensaje): ?><div class="alerta alerta-exito"><?= e($mensaje) ?></div><?php endif; ?>
<section class="panel estado-visitas-resumen"><div class="panel-header"><div><h2>Estado de las visitas</h2><p>Indica si cada estado asignado ya tiene una visita registrada.</p></div></div><div class="estado-visitas-grid"><?php foreach ($asignaciones as $asignacion): ?><div class="estado-visita-card"><div><strong><?= e($asignacion['estado']) ?></strong><small><?= e($asignacion['capital']) ?> · <?= e($asignacion['equipo']) ?></small></div><span class="estado-visita <?= (int) $asignacion['visitas'] > 0 ? 'estado-visita-realizada' : 'estado-visita-pendiente' ?>"><?= (int) $asignacion['visitas'] > 0 ? 'Visitado' : 'No visitado' ?></span></div><?php endforeach; ?></div></section>
<section class="usuarios-layout"><section class="panel usuario-formulario"><div class="panel-header"><div><h2><?= $asignacion_editar ? 'Editar estado asignado' : 'Asignar estado' ?></h2><p>El Equipo 4 administra embajadas y consulados.</p></div></div><form method="POST" action="equipos.php<?= $asignacion_editar ? '?editar=' . (int) $asignacion_editar['id'] : '' ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $asignacion_editar ? 'editar' : 'guardar' ?>"><?php if ($asignacion_editar): ?><input type="hidden" name="asignacion_id" value="<?= (int) $asignacion_editar['id'] ?>"><?php endif; ?><div class="campo"><label for="equipo_id">Equipo operativo</label><select id="equipo_id" name="equipo_id" <?= $rol === 'COORDINADOR' ? 'disabled' : '' ?>><?php foreach ($equipos as $equipo): ?><?php if ($equipo['tipo'] === 'OPERATIVO'): ?><option value="<?= (int) $equipo['id'] ?>" <?= (int) ($asignacion_editar['equipo_id'] ?? $equipo_seleccionado) === (int) $equipo['id'] ? 'selected' : '' ?>><?= e($equipo['nombre']) ?></option><?php endif; ?><?php endforeach; ?></select><?php if ($rol === 'COORDINADOR'): ?><input type="hidden" name="equipo_id" value="<?= $equipo_seleccionado ?>"><?php endif; ?></div><div class="campo"><label for="capital_id">Estado</label><select id="capital_id" name="capital_id" required><option value="">Seleccione...</option><?php if ($asignacion_editar): ?><option value="<?= (int) $asignacion_editar['capital_id'] ?>" selected>Estado actual</option><?php endif; ?><?php foreach ($capitales as $capital): ?><option value="<?= (int) $capital['id'] ?>"><?= e($capital['estado']) ?> - <?= e($capital['capital']) ?><?= $capital['gobernacion'] ? ' - ' . e($capital['gobernacion']) : '' ?></option><?php endforeach; ?></select><small><?= $capitales ? 'Solo aparecen estados todavía no asignados.' : 'Todos los estados disponibles ya fueron asignados.' ?></small></div><button type="submit" class="btn-principal"><?= $asignacion_editar ? 'Guardar cambios' : 'Asignar estado al equipo' ?></button></form></section>
<section class="panel panel-visitas usuarios-listado"><div class="panel-header"><div><h2><?= $ver_todos ? 'Estados de todos los equipos' : 'Estados asignados al equipo' ?></h2><p><?= $total_asignaciones ?> resultados<?= $buscar ? ' para “' . e($buscar) . '”' : '' ?></p></div><?php if ($rol === 'ADMIN'): ?><a href="equipos.php<?= $ver_todos ? '?equipo_id=' . (int) $equipo_seleccionado : '?todos=1' ?>" class="btn-secundario"><?= $ver_todos ? 'Ver un equipo' : 'Ver todos los equipos' ?></a><?php endif; ?></div><form method="GET" action="equipos.php" class="form-busqueda equipos-busqueda"><div class="busqueda-input"><span>⌕</span><input type="search" name="buscar" value="<?= e($buscar) ?>" placeholder="Buscar estado, capital, gobernación, líder o equipo..."><?php if ($rol === 'ADMIN' && !$ver_todos): ?><input type="hidden" name="equipo_id" value="<?= (int) $equipo_seleccionado ?>"><?php elseif ($ver_todos): ?><input type="hidden" name="todos" value="1"><?php endif; ?></div><button type="submit" class="btn-buscar">Buscar</button><?php if ($buscar): ?><a href="equipos.php<?= $ver_todos ? '?todos=1' : ($rol === 'ADMIN' ? '?equipo_id=' . (int) $equipo_seleccionado : '') ?>" class="btn-limpiar">Limpiar</a><?php endif; ?></form><div class="tabla-contenedor"><table class="tabla-visitas"><thead><tr><th>Estado</th><th>Capital</th><th>Gobernación</th><th>Equipo</th><th>Líder</th><th>Acciones</th></tr></thead><tbody><?php foreach ($asignaciones as $asignacion): ?><tr><td><?= e($asignacion['estado']) ?></td><td><strong><?= e($asignacion['capital']) ?></strong></td><td><?= e($asignacion['gobernacion'] ?? 'Pendiente') ?></td><td><?= e($asignacion['equipo']) ?></td><td><?= e($asignacion['lider'] ?? 'Sin líder') ?></td><td><div class="acciones-tabla"><a href="equipos.php?editar=<?= (int) $asignacion['id'] ?>&equipo_id=<?= (int) $asignacion['equipo_id'] ?>" class="btn-tabla btn-editar">Editar</a><form method="POST" class="form-estado"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="asignacion_id" value="<?= (int) $asignacion['id'] ?>"><button type="submit" class="btn-tabla btn-desactivar">Eliminar</button></form></div></td></tr><?php endforeach; ?></tbody></table><?php if (!$asignaciones): ?><div class="reporte-vacio">No hay estados que coincidan con la búsqueda.</div><?php endif; ?></div><?php if ($total_paginas > 1): ?><nav class="paginacion-registros" aria-label="Paginación de estados"><?php for ($numero = 1; $numero <= $total_paginas; $numero++): ?><a class="<?= $numero === $pagina ? 'activo' : '' ?>" href="equipos.php?<?= e(http_build_query(array_filter(['equipo_id' => (!$ver_todos && $rol === 'ADMIN') ? $equipo_seleccionado : null, 'todos' => $ver_todos ? 1 : null, 'buscar' => $buscar ?: null, 'pagina' => $numero]))) ?>"><?= $numero ?></a><?php endfor; ?></nav><?php endif; ?></section></section></main></div>
<script>(function(){var app=document.querySelector('.app'),toggle=document.querySelector('.menu-toggle'),overlay=document.querySelector('.menu-overlay');if(!app||!toggle||!overlay)return;function cerrar(){app.classList.remove('menu-abierto');toggle.setAttribute('aria-expanded','false')}toggle.addEventListener('click',function(){var abierto=app.classList.toggle('menu-abierto');toggle.setAttribute('aria-expanded',abierto?'true':'false')});overlay.addEventListener('click',cerrar);app.querySelectorAll('.menu-item').forEach(function(a){a.addEventListener('click',cerrar)})}())</script>
</body></html>
