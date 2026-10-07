<?php

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../config.php';

$usuario_id = (int) $_SESSION['usuario_id'];
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? '';
$inicial = strtoupper(substr(trim($nombre), 0, 1));
$buscar = trim($_GET['buscar'] ?? '');
$parametro_busqueda = '%' . $buscar . '%';

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

function tamañoArchivo(?int $tamaño): string
{
    if (!$tamaño) {
        return 'Tamaño no disponible';
    }

    if ($tamaño >= 1024 * 1024) {
        return number_format($tamaño / (1024 * 1024), 2, ',', '.') . ' MB';
    }

    return number_format($tamaño / 1024, 0, ',', '.') . ' KB';
}

$categorias_archivo = [
    'INFORMACION_DEMOGRAFICA' => 'Información demográfica',
    'INFORMACION_SOCIAL' => 'Información social',
    'DATOS_ECONOMICOS' => 'Datos económicos (unidades productivas)',
    'OTROS' => 'Otros'
];

$filtro_usuario = $rol === 'ADMIN' ? '' : 'AND v.investigador_id = :usuario_id';

$sql = "SELECT a.id, a.nombre_original, a.ruta, a.extension, a.tipo_mime, a.categoria,
               a.tamaño, a.fecha_subida, v.id AS visita_id,
               v.codigo_visita, v.institucion, v.ciudad,
               u.nombre AS responsable
        FROM archivos a
        INNER JOIN visitas v ON a.visita_id = v.id
        LEFT JOIN usuarios u ON v.investigador_id = u.id
        WHERE (a.nombre_original LIKE :buscar_nombre
               OR v.institucion LIKE :buscar_institucion
               OR v.codigo_visita LIKE :buscar_codigo
               OR a.extension LIKE :buscar_extension)
          $filtro_usuario
        ORDER BY a.fecha_subida DESC, a.id DESC";

$stmt = $pdo->prepare($sql);
$parametros = [
    ':buscar_nombre' => $parametro_busqueda,
    ':buscar_institucion' => $parametro_busqueda,
    ':buscar_codigo' => $parametro_busqueda,
    ':buscar_extension' => $parametro_busqueda
];

if ($rol !== 'ADMIN') {
    $parametros[':usuario_id'] = $usuario_id;
}

$stmt->execute($parametros);
$archivos = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archivos | NEXO</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <link rel="stylesheet" href="../css/visitas.css">
</head>
<body>
<div class="app">
    <button class="menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <div class="menu-overlay"></div>
    <aside class="sidebar">
        <div class="logo">
            <img src="../logo2.png" alt="NEXO" class="logo-sidebar">
        </div>

        <nav class="menu">
            <a href="../index.php" class="menu-item"><span class="icono">⌂</span><span>Inicio</span></a>
            <a href="../visitas/visitas.php" class="menu-item"><span class="icono">▣</span><span>Visitas</span></a>
            <a href="../evidencias/evidencias.php" class="menu-item"><span class="icono">▧</span><span>Evidencias fotográficas</span></a>
            <a href="archivos.php" class="menu-item activo"><span class="icono">□</span><span>Archivos</span></a>
            <a href="../reportes.php" class="menu-item"><span class="icono">▥</span><span>Reportes</span></a>

            <?php if ($rol === 'ADMIN'): ?>
                <div class="menu-separador"></div>
                <a href="../usuarios.php" class="menu-item"><span class="icono">♙</span><span>Usuarios</span></a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="usuario">
                <div class="usuario-avatar"><?= e($inicial) ?></div>
                <div class="usuario-info">
                    <strong><?= e($nombre) ?></strong>
                    <small><?= $rol === 'ADMIN' ? 'Administrador' : 'Responsable del levantamiento' ?></small>
                </div>
            </div>
            <a href="../logout.php" class="cerrar-sesion"><span>↪</span>Cerrar sesión</a>
        </div>
    </aside>

    <main class="contenido">
        <header class="header">
            <div>
                <div class="breadcrumb">NEXO / Archivos</div>
                <h1>Archivos</h1>
                <p>Documentos y otros archivos asociados a las visitas institucionales.</p>
            </div>
            <div class="fecha"><?= date('d/m/Y') ?></div>
        </header>

        <section class="visitas-toolbar">
            <div>
                <h2>Documentos cargados</h2>
                <span><?= count($archivos) ?> <?= count($archivos) === 1 ? 'archivo' : 'archivos' ?></span>
            </div>
        </section>

        <section class="panel panel-busqueda">
            <form method="GET" action="archivos.php" class="form-busqueda">
                <div class="busqueda-input">
                    <span>⌕</span>
                    <input type="text" name="buscar" value="<?= e($buscar) ?>" placeholder="Buscar por archivo, institución, código o extensión...">
                </div>
                <button type="submit" class="btn-buscar">Buscar</button>
                <?php if ($buscar !== ''): ?>
                    <a href="archivos.php" class="btn-limpiar">Limpiar</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="panel panel-visitas">
            <?php if (!empty($archivos)): ?>
                <div class="tabla-contenedor">
                    <table class="tabla-visitas">
                        <thead>
                            <tr>
                                <th>Archivo</th>
                                <th>Visita</th>
                                <th>Tipo</th>
                                <th>Tamaño</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($archivos as $archivo): ?>
                            <tr>
                                <td>
                                    <div class="institucion archivo-nombre">
                                        <strong><?= e($archivo['nombre_original']) ?></strong>
                                        <small><?= e($archivo['tipo_mime'] ?: 'Tipo no disponible') ?></small>
                                        <small><?= e($categorias_archivo[$archivo['categoria']] ?? 'Otros') ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="institucion">
                                        <strong><?= e($archivo['institucion']) ?></strong>
                                        <small><?= e($archivo['codigo_visita']) ?> · <?= e($archivo['ciudad']) ?></small>
                                    </div>
                                </td>
                                <td><span class="codigo-visita">.<?= e(strtoupper($archivo['extension'] ?: 'ARCHIVO')) ?></span></td>
                                <td><?= e(tamañoArchivo((int) $archivo['tamaño'])) ?></td>
                                <td><?= e(date('d/m/Y', strtotime($archivo['fecha_subida']))) ?></td>
                                <td>
                                    <div class="acciones-tabla">
                                        <a href="../<?= e($archivo['ruta']) ?>" target="_blank" rel="noopener" class="btn-tabla btn-ver">Abrir</a>
                                        <a href="../visitas/ver.php?id=<?= (int) $archivo['visita_id'] ?>" class="btn-tabla btn-editar">Ver visita</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="sin-datos visitas-vacio">
                    <div class="sin-datos-icono">□</div>
                    <strong>No hay archivos cargados</strong>
                    <p>Los documentos agregados desde una visita aparecerán aquí.</p>
                    <a href="../visitas/nueva.php">Registrar una visita</a>
                </div>
            <?php endif; ?>
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
