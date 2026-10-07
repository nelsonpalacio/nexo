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
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

if (!$id) {
    header('Location: visitas.php');
    exit;
}

$condicion_acceso = $rol === 'ADMIN'
    ? 'v.id = :id'
    : 'v.id = :id AND v.investigador_id = :usuario_id';

$stmt = $pdo->prepare(
    "SELECT v.*, u.nombre AS responsable
     FROM visitas v
     LEFT JOIN usuarios u ON v.investigador_id = u.id
     WHERE $condicion_acceso"
);

$parametros = [':id' => $id];

if ($rol !== 'ADMIN') {
    $parametros[':usuario_id'] = $usuario_id;
}

$stmt->execute($parametros);
$visita = $stmt->fetch();

if (!$visita) {
    http_response_code(404);
    exit('La visita no existe o no tiene permisos para verla.');
}

$stmt = $pdo->prepare(
    'SELECT id, nombre_archivo AS nombre, ruta_archivo AS ruta, "Fotografía" AS tipo, NULL AS categoria, "evidencia" AS origen
     FROM evidencias WHERE visita_id = :visita_id
     UNION ALL
     SELECT id, nombre_original AS nombre, ruta, "Archivo" AS tipo, categoria, "archivo" AS origen
     FROM archivos WHERE visita_id = :visita_id_archivos
     ORDER BY nombre'
);
$stmt->execute([
    ':visita_id' => $id,
    ':visita_id_archivos' => $id
]);
$adjuntos = $stmt->fetchAll();

$categorias_archivo = [
    'INFORMACION_DEMOGRAFICA' => 'Información demográfica',
    'INFORMACION_SOCIAL' => 'Información social',
    'DATOS_ECONOMICOS' => 'Datos económicos (unidades productivas)',
    'OTROS' => 'Otros'
];

$tipos = [
    'EMBAJADA' => 'Embajada',
    'CANCILLERIA' => 'Cancillería',
    'GOBERNACION' => 'Gobernación',
    'OTRA' => 'Otra'
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de visita | NEXO</title>
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
            <a href="visitas.php" class="menu-item activo"><span class="icono">▣</span><span>Visitas</span></a>
            <a href="../evidencias/evidencias.php" class="menu-item"><span class="icono">▧</span><span>Evidencias fotográficas</span></a>
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
                    <small class="usuario-equipo">Equipo: <?= e($_SESSION['equipo_nombre'] ?? 'Sin equipo asignado') ?></small>
                </div>
            </div>
            <a href="../logout.php" class="cerrar-sesion"><span>↪</span>Cerrar sesión</a>
        </div>
    </aside>

    <main class="contenido">
        <header class="header">
            <div>
                <div class="breadcrumb">NEXO / Visitas / Detalle</div>
                <h1>Detalle de visita</h1>
                <p>Consulta de la información registrada durante la visita institucional.</p>
            </div>
            <div class="fecha"><?= date('d/m/Y') ?></div>
        </header>

        <div class="detalle-acciones">
            <a href="visitas.php" class="btn-cancelar">Volver a visitas</a>
            <a href="editar.php?id=<?= (int) $visita['id'] ?>" class="btn-principal">Editar visita</a>
        </div>

        <section class="panel detalle-visita">
            <div class="detalle-encabezado">
                <div>
                    <span class="codigo-visita"><?= e($visita['codigo_visita']) ?></span>
                    <h2><?= e($visita['institucion']) ?></h2>
                    <p><?= e($visita['dependencia'] ?: 'Sin dependencia registrada') ?></p>
                </div>
                <span class="badge-estado"><?= e($visita['estado_venezuela']) ?></span>
            </div>

            <div class="detalle-seccion">
                <h3>Información de la visita</h3>
                <div class="detalle-grid">
                    <div><span>Tipo de institución</span><strong><?= e($tipos[$visita['tipo_institucion']] ?? $visita['tipo_institucion']) ?></strong></div>
                    <div><span>Estado</span><strong><?= e($visita['estado_venezuela']) ?></strong></div>
                    <div><span>Ciudad</span><strong><?= e($visita['ciudad']) ?></strong></div>
                    <div><span>Fecha de visita</span><strong><?= e(date('d/m/Y', strtotime($visita['fecha_visita']))) ?></strong></div>
                </div>
            </div>

            <div class="detalle-seccion">
                <h3>Persona que entregó la información</h3>
                <div class="detalle-grid">
                    <div><span>Nombre</span><strong><?= e($visita['funcionario'] ?: 'No registrado') ?></strong></div>
                    <div><span>Cargo o función</span><strong><?= e($visita['cargo_funcionario'] ?: 'No registrado') ?></strong></div>
                    <div><span>Teléfono</span><strong><?= e($visita['contacto_funcionario'] ?: 'No registrado') ?></strong></div>
                </div>
            </div>

            <div class="detalle-seccion">
                <h3>Observaciones</h3>
                <p class="detalle-observaciones"><?= nl2br(e($visita['observaciones'] ?: 'No hay observaciones registradas.')) ?></p>
            </div>

            <div class="detalle-seccion">
                <h3>Evidencias fotográficas y archivos</h3>
                <?php if (!empty($adjuntos)): ?>
                    <div class="detalle-adjuntos">
                        <?php foreach ($adjuntos as $adjunto): ?>
                            <a class="detalle-adjunto" href="../descargar.php?tipo=<?= e($adjunto['origen']) ?>&id=<?= (int) $adjunto['id'] ?>" target="_blank" rel="noopener">
                                <span><?= e($adjunto['categoria'] ? 'Archivo · ' . ($categorias_archivo[$adjunto['categoria']] ?? 'Otros') : $adjunto['tipo']) ?></span>
                                <strong><?= e($adjunto['nombre']) ?></strong>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="detalle-vacio">No hay evidencias fotográficas ni archivos asociados.</p>
                <?php endif; ?>
            </div>

            <div class="detalle-responsable">
                <div class="mini-avatar"><?= e(strtoupper(substr(trim($visita['responsable'] ?? 'S'), 0, 1))) ?></div>
                <div><strong>Responsable del levantamiento</strong><span><?= e($visita['responsable'] ?? 'Sin asignar') ?></span></div>
            </div>
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
