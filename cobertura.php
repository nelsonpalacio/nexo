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

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

$filtro_equipo = '';
$parametros_equipo = [];
$equipo_usuario = null;
if ($rol !== 'ADMIN') {
    $stmt = $pdo->prepare(
        'SELECT e.id, e.nombre
         FROM equipo_usuarios eu
         INNER JOIN equipos e ON e.id = eu.equipo_id
         WHERE eu.usuario_id = :usuario_id AND e.estado = 1
         LIMIT 1'
    );
    $stmt->execute([':usuario_id' => $usuario_id]);
    $equipo_usuario = $stmt->fetch();
    if (!$equipo_usuario) {
        http_response_code(403);
        exit('No tiene un equipo asignado para consultar la cobertura.');
    }
    $filtro_equipo = ' AND ac.equipo_id = :equipo_id';
    $parametros_equipo[':equipo_id'] = (int) $equipo_usuario['id'];
}

$stmt = $pdo->prepare(
    "SELECT ac.equipo_id, e.nombre AS equipo, c.estado, c.capital,
            c.gobernacion, COUNT(DISTINCT v.id) AS visitas
     FROM asignaciones_capitales ac
     INNER JOIN equipos e ON e.id = ac.equipo_id
     INNER JOIN capitales c ON c.id = ac.capital_id
     LEFT JOIN visitas v ON v.estado_venezuela = c.estado
    WHERE 1 = 1 $filtro_equipo
     GROUP BY ac.equipo_id, e.nombre, c.id, c.estado, c.capital, c.gobernacion
     ORDER BY ac.equipo_id, c.estado"
);
$stmt->execute($parametros_equipo);
$mapa_estados = $stmt->fetchAll();
$resumen_equipos = [];
$total_estados = count($mapa_estados);
$total_atendidos = 0;

foreach ($mapa_estados as $estado) {
    $equipo_id = (int) $estado['equipo_id'];
    if (!isset($resumen_equipos[$equipo_id])) {
        $resumen_equipos[$equipo_id] = ['equipo' => $estado['equipo'], 'total' => 0, 'atendidos' => 0];
    }
    $resumen_equipos[$equipo_id]['total']++;
    if ((int) $estado['visitas'] > 0) {
        $resumen_equipos[$equipo_id]['atendidos']++;
        $total_atendidos++;
    }
}
$total_pendientes = $total_estados - $total_atendidos;

$coordenadas_capitales = [
    'Distrito Capital' => [10.4806, -66.9036], 'La Guaira' => [10.6000, -66.9333],
    'Miranda' => [10.3428, -67.0436], 'Aragua' => [10.2469, -67.5958],
    'Carabobo' => [10.1620, -68.0077], 'Cojedes' => [9.6583, -68.5747],
    'Barinas' => [8.6226, -70.2075], 'Apure' => [7.8878, -67.4724],
    'Yaracuy' => [10.3399, -68.7425], 'Lara' => [10.0678, -69.3467],
    'Falcón' => [11.4045, -69.6817], 'Zulia' => [10.6666, -71.6124],
    'Portuguesa' => [9.0410, -69.7517], 'Trujillo' => [9.3658, -70.4347],
    'Mérida' => [8.5897, -71.1561], 'Táchira' => [7.7669, -72.2250],
    'Amazonas' => [5.6639, -67.6236], 'Anzoátegui' => [10.1333, -64.6833],
    'Bolívar' => [8.1292, -63.5409], 'Delta Amacuro' => [9.0622, -62.0510],
    'Guárico' => [9.9115, -67.3538], 'Monagas' => [9.7457, -63.1832],
    'Nueva Esparta' => [11.0333, -63.8628], 'Sucre' => [10.4564, -64.1675]
];
$puntos_mapa = [];
foreach ($mapa_estados as $estado) {
    if (isset($coordenadas_capitales[$estado['estado']])) {
        $puntos_mapa[] = [
            'lat' => $coordenadas_capitales[$estado['estado']][0],
            'lng' => $coordenadas_capitales[$estado['estado']][1],
            'estado' => $estado['estado'], 'capital' => $estado['capital'],
            'equipo' => $estado['equipo'], 'equipo_id' => (int) $estado['equipo_id'],
            'atendido' => (int) $estado['visitas'] > 0
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cobertura territorial | NEXO</title>
    <link rel="stylesheet" href="css/estilos.css">
    <link rel="stylesheet" href="css/visitas.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body>
<div class="app">
    <button class="menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false"><span></span><span></span><span></span></button>
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
            <?php if ($rol === 'ADMIN'): ?><a href="usuarios.php" class="menu-item"><span class="icono">♙</span><span>Usuarios</span></a><?php endif; ?>
            <a href="equipos.php" class="menu-item"><span class="icono">◎</span><span>Equipos y estados</span></a>
            <a href="cobertura.php" class="menu-item activo"><span class="icono">◉</span><span>Cobertura territorial</span></a>
        </nav>
        <div class="sidebar-footer">
            <div class="usuario"><div class="usuario-avatar"><?= e($inicial) ?></div><div class="usuario-info"><strong><?= e($nombre) ?></strong><small>Administrador</small><small class="usuario-equipo">Equipo: <?= e($_SESSION['equipo_nombre'] ?? 'Sin equipo asignado') ?></small></div></div>
            <a href="logout.php" class="cerrar-sesion"><span>↪</span>Cerrar sesión</a>
        </div>
    </aside>
    <main class="contenido">
        <header class="header"><div><div class="breadcrumb">NEXO / Cobertura territorial</div><h1>Cobertura territorial</h1><p><?= $rol === 'ADMIN' ? 'Estados asignados por equipo y avance de visitas.' : 'Mapa y avance del equipo ' . e($equipo_usuario['nombre']) . '.' ?></p></div><div class="fecha"><?= date('d/m/Y') ?></div></header>
        <section class="panel cobertura-panel">
            <div class="panel-header"><div><h2>Estados asignados</h2><p>Vista rápida de la cobertura territorial.</p></div><div class="resumen-cobertura"><strong class="resumen-listos"><?= $total_atendidos ?><small>Listos</small></strong><strong class="resumen-pendientes"><?= $total_pendientes ?><small>Pendientes</small></strong></div></div>
            <div class="leyenda-cobertura"><span><i class="estado-indicador indicador-listo"></i>Centro: listo</span><span><i class="estado-indicador indicador-pendiente"></i>Centro: pendiente</span><span><i class="punto-equipo equipo-1"></i>Borde: Equipo 1</span><span><i class="punto-equipo equipo-2"></i>Borde: Equipo 2</span><span><i class="punto-equipo equipo-3"></i>Borde: Equipo 3</span></div>
            <div id="mapa-geografico" class="mapa-geografico" aria-label="Mapa real de Venezuela"></div>
            <div class="mapa-estados">
                <?php foreach ($mapa_estados as $estado): ?>
                    <?php $atendido = (int) $estado['visitas'] > 0; ?>
                    <div class="estado-mapa equipo-<?= (int) $estado['equipo_id'] ?> <?= $atendido ? 'estado-atendido' : 'estado-pendiente' ?>" data-estado="<?= e($estado['estado']) ?>" role="button" tabindex="0" title="Ver <?= e($estado['estado']) ?> en el mapa"><strong><?= e($estado['estado']) ?></strong><small><?= e($estado['capital']) ?></small><em>Equipo <?= (int) $estado['equipo_id'] ?></em><span><?= $atendido ? 'Atendido' : 'Pendiente' ?></span></div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="panel avance-equipos-panel"><div class="panel-header"><div><h2>Avance por equipo</h2><p>Porcentaje de estados con al menos una visita registrada.</p></div></div><div class="tabla-contenedor"><table class="tabla-visitas tabla-avance"><thead><tr><th>Equipo</th><th>Estados asignados</th><th>Atendidos</th><th>Pendientes</th><th>Avance</th></tr></thead><tbody><?php foreach ($resumen_equipos as $resumen): ?><?php $porcentaje = $resumen['total'] ? round(($resumen['atendidos'] / $resumen['total']) * 100) : 0; ?><tr><td><strong><?= e($resumen['equipo']) ?></strong></td><td><?= $resumen['total'] ?></td><td><?= $resumen['atendidos'] ?></td><td><?= $resumen['total'] - $resumen['atendidos'] ?></td><td><div class="avance-celda"><div class="avance-barra"><i style="width: <?= $porcentaje ?>%"></i></div><strong><?= $porcentaje ?>%</strong></div></td></tr><?php endforeach; ?></tbody></table></div></section>
    </main>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    (function () {
        var mapa = L.map('mapa-geografico', { minZoom: 5, maxZoom: 9 });
        var limites = L.latLngBounds([[0.5, -73.5], [12.5, -59.5]]);
        mapa.fitBounds(limites);
        mapa.setMaxBounds(limites);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(mapa);
        var puntos = <?= json_encode($puntos_mapa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var marcadores = {};
        var marcadorActivo = null;

        puntos.forEach(function (punto) {
            var colorEquipo = {1: '#2f6fed', 2: '#d97706', 3: '#16835b'}[punto.equipo_id] || '#596273';
            var colorEstado = punto.atendido ? '#16835b' : '#f59e0b';
            var estadoTexto = punto.atendido ? 'LISTO' : 'PENDIENTE';
            var marcador = L.circleMarker([punto.lat, punto.lng], { radius: 8, color: colorEquipo, fillColor: colorEstado, fillOpacity: 0.95, weight: 3 }).addTo(mapa)
                .bindPopup('<strong>' + punto.estado + '</strong><br>' + punto.capital + '<br>' + punto.equipo + '<br>' + estadoTexto);
            marcadores[punto.estado] = marcador;
        });

        function enfocarEstado(tarjeta) {
            var marcador = marcadores[tarjeta.getAttribute('data-estado')];
            if (!marcador) return;
            if (marcadorActivo) marcadorActivo.setStyle({ radius: 8, weight: 3 });
            document.querySelectorAll('.estado-mapa.seleccionado').forEach(function (elemento) { elemento.classList.remove('seleccionado'); });
            marcadorActivo = marcador;
            marcador.setStyle({ radius: 14, weight: 5 });
            tarjeta.classList.add('seleccionado');
            mapa.flyTo(marcador.getLatLng(), 7, { duration: 0.6 });
            marcador.openPopup();
        }

        document.querySelectorAll('.estado-mapa').forEach(function (tarjeta) {
            tarjeta.addEventListener('click', function () { enfocarEstado(tarjeta); });
            tarjeta.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter' || evento.key === ' ') {
                    evento.preventDefault();
                    enfocarEstado(tarjeta);
                }
            });
        });
    }());
</script>
<script>(function(){var app=document.querySelector('.app'),toggle=document.querySelector('.menu-toggle'),overlay=document.querySelector('.menu-overlay');if(!app||!toggle||!overlay)return;function cerrar(){app.classList.remove('menu-abierto');toggle.setAttribute('aria-expanded','false')}toggle.addEventListener('click',function(){var abierto=app.classList.toggle('menu-abierto');toggle.setAttribute('aria-expanded',abierto?'true':'false')});overlay.addEventListener('click',cerrar);app.querySelectorAll('.menu-item').forEach(function(a){a.addEventListener('click',cerrar)})}())</script>
</body>
</html>
