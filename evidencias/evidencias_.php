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

$filtro_usuario = $rol === 'ADMIN' ? '' : 'AND v.investigador_id = :usuario_id';

$sql = "SELECT e.id, e.nombre_archivo, e.ruta_archivo, e.fecha_captura,
               v.id AS visita_id, v.codigo_visita, v.institucion,
               v.ciudad, v.fecha_visita, u.nombre AS responsable
        FROM evidencias e
        INNER JOIN visitas v ON e.visita_id = v.id
        LEFT JOIN usuarios u ON v.investigador_id = u.id
        WHERE e.tipo_evidencia = 'FOTOGRAFIA'
          AND (v.institucion LIKE :buscar_institucion
               OR v.codigo_visita LIKE :buscar_codigo
               OR v.ciudad LIKE :buscar_ciudad
               OR e.nombre_archivo LIKE :buscar_archivo)
          $filtro_usuario
        ORDER BY e.fecha_creacion DESC, e.id DESC";

$stmt = $pdo->prepare($sql);
$parametros = [
    ':buscar_institucion' => $parametro_busqueda,
    ':buscar_codigo' => $parametro_busqueda,
    ':buscar_ciudad' => $parametro_busqueda,
    ':buscar_archivo' => $parametro_busqueda
];

if ($rol !== 'ADMIN') {
    $parametros[':usuario_id'] = $usuario_id;
}

$stmt->execute($parametros);
$evidencias = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evidencias fotográficas | NEXO</title>
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
            <a href="evidencias.php" class="menu-item activo"><span class="icono">▧</span><span>Evidencias fotográficas</span></a>
            <a href="../archivos/archivos.php" class="menu-item"><span class="icono">□</span><span>Archivos</span></a>
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
                <div class="breadcrumb">NEXO / Evidencias fotográficas</div>
                <h1>Evidencias fotográficas</h1>
                <p>Fotografías asociadas a las visitas institucionales.</p>
            </div>
            <div class="fecha"><?= date('d/m/Y') ?></div>
        </header>

        <section class="visitas-toolbar">
            <div>
                <h2>Fotografías registradas</h2>
                <span><?= count($evidencias) ?> <?= count($evidencias) === 1 ? 'evidencia' : 'evidencias' ?></span>
            </div>
        </section>

        <section class="panel panel-busqueda">
            <form method="GET" action="evidencias.php" class="form-busqueda">
                <div class="busqueda-input">
                    <span>⌕</span>
                    <input type="text" name="buscar" value="<?= e($buscar) ?>" placeholder="Buscar por institución, código, ciudad o archivo...">
                </div>
                <button type="submit" class="btn-buscar">Buscar</button>
                <?php if ($buscar !== ''): ?>
                    <a href="evidencias.php" class="btn-limpiar">Limpiar</a>
                <?php endif; ?>
            </form>
        </section>

        <?php if (!empty($evidencias)): ?>
            <section class="evidencias-grid">
                <?php foreach ($evidencias as $evidencia): ?>
                    <article class="evidencia-fotografica">
                        <a href="../<?= e($evidencia['ruta_archivo']) ?>" target="_blank" rel="noopener" class="evidencia-imagen">
                            <img src="../<?= e($evidencia['ruta_archivo']) ?>" alt="<?= e($evidencia['nombre_archivo']) ?>">
                        </a>
                        <div class="evidencia-contenido">
                            <strong><?= e($evidencia['institucion']) ?></strong>
                            <span><?= e($evidencia['codigo_visita']) ?> · <?= e($evidencia['ciudad']) ?></span>
                            <small><?= e($evidencia['nombre_archivo']) ?></small>
                            <a href="../visitas/ver.php?id=<?= (int) $evidencia['visita_id'] ?>" class="btn-tabla btn-ver">Ver visita</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <section class="panel sin-datos visitas-vacio">
                <div class="sin-datos-icono">▧</div>
                <strong>No hay evidencias fotográficas</strong>
                <p>Las fotografías cargadas desde una visita aparecerán aquí.</p>
                <a href="../visitas/nueva.php">Registrar una visita</a>
            </section>
        <?php endif; ?>
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
