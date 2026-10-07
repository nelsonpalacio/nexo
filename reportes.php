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

$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');
$institucion = trim($_GET['institucion'] ?? '');
$estado = trim($_GET['estado'] ?? '');
$ciudad = trim($_GET['ciudad'] ?? '');

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

function fechaValida(string $fecha): bool
{
    $fecha_objeto = DateTime::createFromFormat('Y-m-d', $fecha);
    return $fecha_objeto !== false && $fecha_objeto->format('Y-m-d') === $fecha;
}

function mesEnEspanol(string $mes): string
{
    $meses = [
        1 => 'Enero',
        2 => 'Febrero',
        3 => 'Marzo',
        4 => 'Abril',
        5 => 'Mayo',
        6 => 'Junio',
        7 => 'Julio',
        8 => 'Agosto',
        9 => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre'
    ];

    $fecha = DateTime::createFromFormat('Y-m', $mes);
    $numero_mes = $fecha ? (int) $fecha->format('n') : 0;
    $anio = $fecha ? $fecha->format('Y') : $mes;

    return ($meses[$numero_mes] ?? $mes) . ' ' . $anio;
}

$condiciones = ['1 = 1'];
$parametros = [];

if ($rol !== 'ADMIN') {
    $condiciones[] = 'v.investigador_id = :usuario_id';
    $parametros[':usuario_id'] = $usuario_id;
}

if (fechaValida($desde)) {
    $condiciones[] = 'v.fecha_visita >= :desde';
    $parametros[':desde'] = $desde;
}

if (fechaValida($hasta)) {
    $condiciones[] = 'v.fecha_visita <= :hasta';
    $parametros[':hasta'] = $hasta;
}

if ($institucion !== '') {
    $condiciones[] = 'v.institucion LIKE :institucion';
    $parametros[':institucion'] = '%' . $institucion . '%';
}

if ($estado !== '') {
    $condiciones[] = 'v.estado_venezuela LIKE :estado';
    $parametros[':estado'] = '%' . $estado . '%';
}

if ($ciudad !== '') {
    $condiciones[] = 'v.ciudad LIKE :ciudad';
    $parametros[':ciudad'] = '%' . $ciudad . '%';
}

$where = implode(' AND ', $condiciones);

$sql_visitas = "SELECT v.id, v.codigo_visita, v.institucion, v.tipo_institucion,
                       v.estado_venezuela, v.ciudad, v.fecha_visita,
                       v.funcionario, v.cargo_funcionario,
                       u.nombre AS responsable,
                       (SELECT COUNT(*) FROM evidencias e WHERE e.visita_id = v.id AND e.tipo_evidencia = 'FOTOGRAFIA') AS evidencias,
                       (SELECT COUNT(*) FROM archivos a WHERE a.visita_id = v.id) AS archivos
                FROM visitas v
                LEFT JOIN usuarios u ON v.investigador_id = u.id
                WHERE $where
                ORDER BY v.fecha_visita DESC, v.id DESC";

$stmt = $pdo->prepare($sql_visitas);
$stmt->execute($parametros);
$visitas = $stmt->fetchAll();

$resumen_equipos = [];
if ($rol === 'ADMIN') {
    $condiciones_equipos = ['c.estado_activo = 1'];
    $parametros_equipos = [];
    $filtros_visitas_equipo = [];

    if (fechaValida($desde)) {
        $filtros_visitas_equipo[] = 'v.fecha_visita >= :equipo_desde';
        $parametros_equipos[':equipo_desde'] = $desde;
    }
    if (fechaValida($hasta)) {
        $filtros_visitas_equipo[] = 'v.fecha_visita <= :equipo_hasta';
        $parametros_equipos[':equipo_hasta'] = $hasta;
    }
    if ($institucion !== '') {
        $filtros_visitas_equipo[] = 'v.institucion LIKE :equipo_institucion';
        $parametros_equipos[':equipo_institucion'] = '%' . $institucion . '%';
    }
    if ($estado !== '') {
        $condiciones_equipos[] = 'c.estado LIKE :equipo_estado';
        $parametros_equipos[':equipo_estado'] = '%' . $estado . '%';
    }
    if ($ciudad !== '') {
        $filtros_visitas_equipo[] = 'v.ciudad LIKE :equipo_ciudad';
        $parametros_equipos[':equipo_ciudad'] = '%' . $ciudad . '%';
    }

    $filtros_join = $filtros_visitas_equipo
        ? ' AND ' . implode(' AND ', $filtros_visitas_equipo)
        : '';
    $condiciones_equipos_sql = implode(' AND ', $condiciones_equipos);

    $stmt_equipos = $pdo->prepare(
        "SELECT e.id, e.nombre AS equipo,
                COUNT(DISTINCT ac.capital_id) AS estados_asignados,
                COUNT(DISTINCT CASE WHEN v.id IS NOT NULL THEN ac.capital_id END) AS estados_visitados
         FROM equipos e
         INNER JOIN asignaciones_capitales ac ON ac.equipo_id = e.id
         INNER JOIN capitales c ON c.id = ac.capital_id
         LEFT JOIN visitas v ON v.estado_venezuela = c.estado $filtros_join
         WHERE e.estado = 1 AND $condiciones_equipos_sql
         GROUP BY e.id, e.nombre
         ORDER BY e.id"
    );
    $stmt_equipos->execute($parametros_equipos);
    $resumen_equipos = $stmt_equipos->fetchAll();
}

if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte-visitas-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $salida = fopen('php://output', 'w');
    fputcsv($salida, ['Código', 'Institución', 'Tipo', 'Estado', 'Ciudad', 'Fecha', 'Persona informante', 'Cargo', 'Evidencias fotográficas', 'Archivos'], ';');

    foreach ($visitas as $visita) {
        fputcsv($salida, [
            $visita['codigo_visita'],
            $visita['institucion'],
            $visita['tipo_institucion'],
            $visita['estado_venezuela'],
            $visita['ciudad'],
            $visita['fecha_visita'],
            $visita['funcionario'],
            $visita['cargo_funcionario'],
            $visita['evidencias'],
            $visita['archivos']
        ], ';');
    }

    fclose($salida);
    exit;
}

$total_visitas = count($visitas);
$total_evidencias = array_sum(array_column($visitas, 'evidencias'));
$total_archivos = array_sum(array_column($visitas, 'archivos'));
$instituciones = count(array_unique(array_column($visitas, 'institucion')));
$tipos = [
    'EMBAJADA' => 'Embajada',
    'CANCILLERIA' => 'Cancillería',
    'GOBERNACION' => 'Gobernación',
    'OTRA' => 'Otra'
];

$por_estado = [];
$por_institucion = [];
$por_tipo = [];
$por_mes = [];
$adjuntos_por_visita = [];

foreach ($visitas as $visita) {
    $nombre_estado = $visita['estado_venezuela'] ?: 'Sin estado';
    $nombre_institucion = $visita['institucion'] ?: 'Sin institución';
    $nombre_tipo = $tipos[$visita['tipo_institucion']] ?? $visita['tipo_institucion'];
    $mes = date('Y-m', strtotime($visita['fecha_visita']));
    $por_estado[$nombre_estado] = ($por_estado[$nombre_estado] ?? 0) + 1;
    $por_institucion[$nombre_institucion] = ($por_institucion[$nombre_institucion] ?? 0) + 1;
    $por_tipo[$nombre_tipo] = ($por_tipo[$nombre_tipo] ?? 0) + 1;
    $por_mes[$mes] = ($por_mes[$mes] ?? 0) + 1;
    $adjuntos_por_visita[$visita['codigo_visita']] = (int) $visita['evidencias'] + (int) $visita['archivos'];
}

arsort($por_estado);
arsort($por_institucion);
$por_tipo = array_slice($por_tipo, 0, 6, true);
ksort($por_mes);
arsort($adjuntos_por_visita);
$por_estado = array_slice($por_estado, 0, 6, true);
$por_institucion = array_slice($por_institucion, 0, 6, true);
$adjuntos_por_visita = array_slice($adjuntos_por_visita, 0, 6, true);
$max_estado = max($por_estado ?: [1]);
$max_institucion = max($por_institucion ?: [1]);
$max_tipo = max($por_tipo ?: [1]);
$max_mes = max($por_mes ?: [1]);
$max_adjuntos = max($adjuntos_por_visita ?: [1]);
$tipo_colores = ['#002f6c', '#d97706', '#16835b', '#6d4bc3'];
$total_tipos = array_sum($por_tipo);
$segmentos_torta = [];
$acumulado_torta = 0;

foreach (array_values($por_tipo) as $indice => $cantidad) {
    $inicio = $total_tipos > 0 ? ($acumulado_torta / $total_tipos) * 100 : 0;
    $acumulado_torta += $cantidad;
    $fin = $total_tipos > 0 ? ($acumulado_torta / $total_tipos) * 100 : 0;
    $segmentos_torta[] = ($tipo_colores[$indice % count($tipo_colores)] . ' ' . $inicio . '% ' . $fin . '%');
}
$fondo_torta = $segmentos_torta ? implode(', ', $segmentos_torta) : '#eef1f5 0 100%';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes | NEXO</title>
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
            <a href="reportes.php" class="menu-item activo"><span class="icono">▥</span><span>Reportes</span></a>
            <a href="equipos.php" class="menu-item"><span class="icono">◎</span><span>Equipos y estados</span></a>
            <a href="cobertura.php" class="menu-item"><span class="icono">◉</span><span>Cobertura territorial</span></a>
            <?php if ($rol === 'ADMIN'): ?>
                <div class="menu-separador"></div>
                <a href="usuarios.php" class="menu-item"><span class="icono">♙</span><span>Usuarios</span></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="usuario">
                <div class="usuario-avatar"><?= e($inicial) ?></div>
                <div class="usuario-info"><strong><?= e($nombre) ?></strong><small><?= $rol === 'ADMIN' ? 'Administrador' : 'Responsable del levantamiento' ?></small><small class="usuario-equipo">Equipo: <?= e($_SESSION['equipo_nombre'] ?? 'Sin equipo asignado') ?></small></div>
            </div>
            <a href="logout.php" class="cerrar-sesion"><span>↪</span>Cerrar sesión</a>
        </div>
    </aside>

    <main class="contenido">
        <header class="header">
            <div>
                <div class="breadcrumb">NEXO / Reportes</div>
                <h1>Reportes</h1>
                <p>Analice las visitas registradas y descargue los resultados filtrados.</p>
            </div>
            <div class="fecha"><?= date('d/m/Y') ?></div>
        </header>

        <section class="panel reportes-filtros">
            <div class="reportes-filtros-cabecera">
                <div><h2>Filtrar información</h2><p>Combine los criterios que necesite para revisar el período.</p></div>
                <a href="reportes.php" class="btn-limpiar">Limpiar</a>
            </div>
            <form method="GET" action="reportes.php" class="reportes-formulario">
                <div class="campo"><label for="desde">Desde</label><input type="date" id="desde" name="desde" value="<?= e($desde) ?>"></div>
                <div class="campo"><label for="hasta">Hasta</label><input type="date" id="hasta" name="hasta" value="<?= e($hasta) ?>"></div>
                <div class="campo"><label for="institucion">Institución</label><input type="text" id="institucion" name="institucion" value="<?= e($institucion) ?>" placeholder="Nombre de institución"></div>
                <div class="campo"><label for="estado">Estado</label><input type="text" id="estado" name="estado" value="<?= e($estado) ?>" placeholder="Ejemplo: Zulia"></div>
                <div class="campo"><label for="ciudad">Ciudad</label><input type="text" id="ciudad" name="ciudad" value="<?= e($ciudad) ?>" placeholder="Ejemplo: Maracaibo"></div>
                <button type="submit" class="btn-principal">Aplicar filtros</button>
            </form>
        </section>

        <div class="reportes-encabezado-resultados">
            <div><h2>Resumen del período</h2><span><?= $total_visitas ?> <?= $total_visitas === 1 ? 'visita encontrada' : 'visitas encontradas' ?></span></div>
            <a href="<?= e('reportes.php?' . http_build_query(array_merge($_GET, ['formato' => 'csv']))) ?>" class="btn-secundario">Descargar CSV</a>
        </div>

        <section class="reportes-metricas">
            <div class="reporte-metrica reporte-metrica-azul"><span>Visitas</span><strong><?= $total_visitas ?></strong><small>Según los filtros activos</small></div>
            <div class="reporte-metrica reporte-metrica-naranja"><span>Evidencias fotográficas</span><strong><?= $total_evidencias ?></strong><small>Fotografías asociadas</small></div>
            <div class="reporte-metrica reporte-metrica-morado"><span>Archivos</span><strong><?= $total_archivos ?></strong><small>Documentos asociados</small></div>
            <div class="reporte-metrica reporte-metrica-verde"><span>Instituciones</span><strong><?= $instituciones ?></strong><small>Instituciones representadas</small></div>
        </section>

        <?php if ($rol === 'ADMIN'): ?>
            <section class="panel reporte-panel reporte-equipos-panel">
                <div class="panel-header">
                    <div>
                        <h2>Estado de visitas por equipo</h2>
                        <p>Seguimiento de estados asignados dentro de los filtros actuales.</p>
                    </div>
                </div>
                <?php if ($resumen_equipos): ?>
                    <div class="tabla-contenedor">
                        <table class="tabla-visitas tabla-avance">
                            <thead>
                                <tr>
                                    <th>Equipo</th>
                                    <th>Estados asignados</th>
                                    <th>Visitados</th>
                                    <th>Pendientes</th>
                                    <th>Avance</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($resumen_equipos as $equipo): ?>
                                <?php
                                $asignados = (int) $equipo['estados_asignados'];
                                $visitados = (int) $equipo['estados_visitados'];
                                $pendientes = $asignados - $visitados;
                                $avance = $asignados > 0 ? round(($visitados / $asignados) * 100) : 0;
                                ?>
                                <tr>
                                    <td><strong><?= e($equipo['equipo']) ?></strong></td>
                                    <td><?= $asignados ?></td>
                                    <td><span class="estado-visita estado-visita-realizada"><?= $visitados ?></span></td>
                                    <td><span class="estado-visita estado-visita-pendiente"><?= $pendientes ?></span></td>
                                    <td><div class="avance-celda"><div class="avance-barra"><i style="width: <?= $avance ?>%"></i></div><strong><?= $avance ?>%</strong></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="reporte-vacio">No hay estados asignados para mostrar.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="reportes-graficos">
            <div class="panel reporte-panel"><div class="panel-header"><div><h2>Visitas por estado</h2><p>Distribución territorial del resultado</p></div></div><div class="barras-reporte"><?php if ($por_estado): ?><?php foreach ($por_estado as $nombre_estado => $cantidad): ?><div class="barra-fila"><div><span><?= e($nombre_estado) ?></span><strong><?= $cantidad ?></strong></div><div class="barra-fondo"><i style="width: <?= (int) (($cantidad / $max_estado) * 100) ?>%"></i></div></div><?php endforeach; ?><?php else: ?><p class="reporte-vacio">No hay datos para mostrar.</p><?php endif; ?></div></div>
            <div class="panel reporte-panel"><div class="panel-header"><div><h2>Instituciones con más visitas</h2><p>Principales registros del período</p></div></div><div class="barras-reporte"><?php if ($por_institucion): ?><?php foreach ($por_institucion as $nombre_institucion => $cantidad): ?><div class="barra-fila"><div><span><?= e($nombre_institucion) ?></span><strong><?= $cantidad ?></strong></div><div class="barra-fondo barra-fondo-verde"><i style="width: <?= (int) (($cantidad / $max_institucion) * 100) ?>%"></i></div></div><?php endforeach; ?><?php else: ?><p class="reporte-vacio">No hay datos para mostrar.</p><?php endif; ?></div></div>
        </section>

        <section class="reportes-graficos reportes-graficos-secundarios">
            <div class="panel reporte-panel"><div class="panel-header"><div><h2>Visitas por tipo</h2><p>Clasificación de las instituciones visitadas</p></div></div><div class="barras-reporte"><?php if ($por_tipo): ?><?php foreach ($por_tipo as $nombre_tipo => $cantidad): ?><div class="barra-fila"><div><span><?= e($nombre_tipo) ?></span><strong><?= $cantidad ?></strong></div><div class="barra-fondo barra-fondo-naranja"><i style="width: <?= (int) (($cantidad / $max_tipo) * 100) ?>%"></i></div></div><?php endforeach; ?><?php else: ?><p class="reporte-vacio">No hay datos para mostrar.</p><?php endif; ?></div></div>
            <div class="panel reporte-panel"><div class="panel-header"><div><h2>Actividad mensual</h2><p>Visitas registradas por mes</p></div></div><div class="barras-reporte"><?php if ($por_mes): ?><?php foreach ($por_mes as $mes => $cantidad): ?><div class="barra-fila"><div><span><?= e(mesEnEspanol($mes)) ?></span><strong><?= $cantidad ?></strong></div><div class="barra-fondo barra-fondo-morado"><i style="width: <?= (int) (($cantidad / $max_mes) * 100) ?>%"></i></div></div><?php endforeach; ?><?php else: ?><p class="reporte-vacio">No hay datos para mostrar.</p><?php endif; ?></div></div>
        </section>

        <section class="reportes-graficos reportes-graficos-visuales">
            <div class="panel reporte-panel">
                <div class="panel-header"><div><h2>Comparación por tipo</h2><p>Gráfica de barras de las visitas filtradas</p></div></div>
                <?php if ($por_tipo): ?>
                    <div class="grafica-barras-verticales">
                        <?php foreach ($por_tipo as $indice => $datos_tipo): ?>
                            <?php $altura_barra = (int) (($datos_tipo / $max_tipo) * 100); ?>
                            <div class="barra-vertical-columna">
                                <strong><?= $datos_tipo ?></strong>
                                <div class="barra-vertical-fondo"><i style="height: <?= $altura_barra ?>%"></i></div>
                                <span><?= e($indice) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="reporte-vacio">No hay datos para mostrar.</p>
                <?php endif; ?>
            </div>

            <div class="panel reporte-panel">
                <div class="panel-header"><div><h2>Distribución por tipo</h2><p>Participación porcentual de las visitas</p></div></div>
                <?php if ($por_tipo): ?>
                    <div class="grafica-torta-contenido">
                        <div class="grafica-torta" style="background: conic-gradient(<?= e($fondo_torta) ?>)"></div>
                        <div class="leyenda-torta">
                            <?php foreach ($por_tipo as $indice => $cantidad): ?>
                                <?php $porcentaje = $total_tipos > 0 ? round(($cantidad / $total_tipos) * 100) : 0; ?>
                                <?php $color = $tipo_colores[array_search($indice, array_keys($por_tipo), true) % count($tipo_colores)]; ?>
                                <div><span class="leyenda-punto" style="background: <?= e($color) ?>"></span><span><?= e($indice) ?></span><strong><?= $porcentaje ?>%</strong></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="reporte-vacio">No hay datos para mostrar.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel reporte-panel reporte-adjuntos-panel">
            <div class="panel-header"><div><h2>Visitas con más adjuntos</h2><p>Fotografías y archivos asociados a cada visita</p></div></div>
            <div class="barras-reporte barras-adjuntos">
                <?php if ($adjuntos_por_visita): ?>
                    <?php foreach ($adjuntos_por_visita as $codigo => $cantidad): ?>
                        <div class="barra-fila"><div><span><?= e($codigo) ?></span><strong><?= $cantidad ?> <?= $cantidad === 1 ? 'adjunto' : 'adjuntos' ?></strong></div><div class="barra-fondo barra-fondo-gris"><i style="width: <?= (int) (($cantidad / $max_adjuntos) * 100) ?>%"></i></div></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="reporte-vacio">No hay adjuntos para mostrar.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel panel-visitas reporte-tabla">
            <div class="panel-header"><div><h2>Detalle de visitas</h2><p>Información incluida en el resultado actual</p></div></div>
            <?php if ($visitas): ?><div class="tabla-contenedor"><table class="tabla-visitas"><thead><tr><th>Visita</th><th>Institución</th><th>Ubicación</th><th>Fecha</th><th>Adjuntos</th><th>Acción</th></tr></thead><tbody><?php foreach ($visitas as $visita): ?><tr><td><span class="codigo-visita"><?= e($visita['codigo_visita']) ?></span></td><td><div class="institucion"><strong><?= e($visita['institucion']) ?></strong><small><?= e($tipos[$visita['tipo_institucion']] ?? $visita['tipo_institucion']) ?></small></div></td><td><?= e($visita['ciudad']) ?><br><small><?= e($visita['estado_venezuela']) ?></small></td><td><?= e(date('d/m/Y', strtotime($visita['fecha_visita']))) ?></td><td><?= (int) $visita['evidencias'] ?> foto<?= (int) $visita['evidencias'] === 1 ? '' : 's' ?> · <?= (int) $visita['archivos'] ?> archivo<?= (int) $visita['archivos'] === 1 ? '' : 's' ?></td><td><a href="visitas/ver.php?id=<?= (int) $visita['id'] ?>" class="btn-tabla btn-ver">Ver</a></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="sin-datos reporte-vacio"><strong>No hay visitas para estos filtros</strong><p>Pruebe con un rango o criterio diferente.</p></div><?php endif; ?>
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
