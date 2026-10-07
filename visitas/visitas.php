<?php

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config.php";

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'] ?? '';
$nombre = $_SESSION['nombre'] ?? 'Usuario';

$buscar = trim($_GET['buscar'] ?? '');
$buscarParametro = '%' . $buscar . '%';

if ($rol === 'ADMIN') {

    $sql = "
        SELECT
            v.id,
            v.codigo_visita,
            v.institucion,
            v.tipo_institucion,
            v.estado_venezuela,
            v.ciudad,
            v.dependencia,
            v.fecha_visita,
            v.funcionario,
            v.cargo_funcionario,
            v.contacto_funcionario,
            v.investigador_id,
            v.observaciones,
            v.fecha_creacion,
            u.nombre AS responsable
        FROM visitas v
        LEFT JOIN usuarios u ON v.investigador_id = u.id
        WHERE
            v.codigo_visita LIKE :codigo
            OR v.institucion LIKE :institucion
            OR v.tipo_institucion LIKE :tipo
            OR v.estado_venezuela LIKE :estado
            OR v.ciudad LIKE :ciudad
            OR v.dependencia LIKE :dependencia
            OR v.funcionario LIKE :funcionario
        ORDER BY v.fecha_visita DESC, v.id DESC
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':codigo' => $buscarParametro,
        ':institucion' => $buscarParametro,
        ':tipo' => $buscarParametro,
        ':estado' => $buscarParametro,
        ':ciudad' => $buscarParametro,
        ':dependencia' => $buscarParametro,
        ':funcionario' => $buscarParametro
    ]);

} else {

    $sql = "
        SELECT
            v.id,
            v.codigo_visita,
            v.institucion,
            v.tipo_institucion,
            v.estado_venezuela,
            v.ciudad,
            v.dependencia,
            v.fecha_visita,
            v.funcionario,
            v.cargo_funcionario,
            v.contacto_funcionario,
            v.investigador_id,
            v.observaciones,
            v.fecha_creacion,
            u.nombre AS responsable
        FROM visitas v
        LEFT JOIN usuarios u ON v.investigador_id = u.id
        WHERE
            v.investigador_id = :usuario_id
            AND (
                v.codigo_visita LIKE :codigo
                OR v.institucion LIKE :institucion
                OR v.tipo_institucion LIKE :tipo
                OR v.estado_venezuela LIKE :estado
                OR v.ciudad LIKE :ciudad
                OR v.dependencia LIKE :dependencia
                OR v.funcionario LIKE :funcionario
            )
        ORDER BY v.fecha_visita DESC, v.id DESC
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':usuario_id' => $usuario_id,
        ':codigo' => $buscarParametro,
        ':institucion' => $buscarParametro,
        ':tipo' => $buscarParametro,
        ':estado' => $buscarParametro,
        ':ciudad' => $buscarParametro,
        ':dependencia' => $buscarParametro,
        ':funcionario' => $buscarParametro
    ]);
}

$visitas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_visitas = count($visitas);

$inicial = strtoupper(substr(trim($nombre), 0, 1));

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Visitas | NEXO</title>

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

            <a href="../index.php" class="menu-item">
                <span class="icono">⌂</span>
                <span>Inicio</span>
            </a>

            <a href="visitas.php" class="menu-item activo">
                <span class="icono">▣</span>
                <span>Visitas</span>
            </a>

            <a href="../evidencias/evidencias.php" class="menu-item">
                <span class="icono">▧</span>
                <span>Evidencias fotográficas</span>
            </a>

            <a href="../archivos/archivos.php" class="menu-item">
                <span class="icono">□</span>
                <span>Archivos</span>
            </a>

            <a href="../reportes.php" class="menu-item">
                <span class="icono">▥</span>
                <span>Reportes</span>
            </a>
            <a href="../equipos.php" class="menu-item">
                <span class="icono">◎</span>
                <span>Equipos y estados</span>
            </a>
            <a href="../cobertura.php" class="menu-item">
                <span class="icono">◉</span>
                <span>Cobertura territorial</span>
            </a>

            <?php if ($rol === 'ADMIN'): ?>

                <div class="menu-separador"></div>

                <a href="../usuarios.php" class="menu-item">
                    <span class="icono">♙</span>
                    <span>Usuarios</span>
                </a>

            <?php endif; ?>

        </nav>

        <div class="sidebar-footer">

            <div class="usuario">

                <div class="usuario-avatar">
                    <?= htmlspecialchars($inicial) ?>
                </div>

                <div class="usuario-info">

                    <strong>
                        <?= htmlspecialchars($nombre) ?>
                    </strong>

                    <small>

                        <?php
                        echo ($rol === 'ADMIN')
                            ? 'Administrador'
                            : 'Responsable del levantamiento';
                        ?>

                    </small>

                    <small class="usuario-equipo">
                        Equipo: <?= htmlspecialchars($_SESSION['equipo_nombre'] ?? 'Sin equipo asignado') ?>
                    </small>

                </div>

            </div>

            <a href="../logout.php" class="cerrar-sesion">
                <span>↪</span>
                Cerrar sesión
            </a>

        </div>

    </aside>


    <main class="contenido">

        <header class="header">

            <div>

                <div class="breadcrumb">
                    NEXO / Visitas
                </div>

                <h1>
                    Visitas
                </h1>

                <p>
                    Registro y seguimiento de visitas institucionales
                </p>

            </div>

            <div class="fecha">
                <?= date('d/m/Y') ?>
            </div>

        </header>


        <section class="visitas-toolbar">

            <div>

                <h2>
                    Visitas registradas
                </h2>

                <span>

                    <?= $total_visitas ?>

                    <?= ($total_visitas == 1)
                        ? 'registro'
                        : 'registros'
                    ?>

                </span>

            </div>

            <div class="visitas-toolbar-acciones">
                <a href="../evidencias/evidencias.php" class="btn-secundario">
                    Evidencias fotográficas
                </a>
                <a href="nueva.php" class="btn-principal">
                    + Nueva visita
                </a>
            </div>

        </section>


        <section class="panel panel-busqueda">

            <form method="GET" action="visitas.php" class="form-busqueda">

                <div class="busqueda-input">

                    <span>⌕</span>

                    <input
                        type="text"
                        name="buscar"
                        value="<?= htmlspecialchars($buscar) ?>"
                        placeholder="Buscar por código, institución, ciudad o estado..."
                    >

                </div>

                <button type="submit" class="btn-buscar">
                    Buscar
                </button>

                <?php if ($buscar !== ''): ?>

                    <a href="visitas.php" class="btn-limpiar">
                        Limpiar
                    </a>

                <?php endif; ?>

            </form>

        </section>


        <section class="panel panel-visitas">

            <?php if (!empty($visitas)): ?>

                <div class="tabla-contenedor">

                    <table class="tabla-visitas">

                        <thead>

                            <tr>

                                <th>Código</th>

                                <th>Institución</th>

                                <th>Tipo</th>

                                <th>Estado</th>

                                <th>Ciudad</th>

                                <th>Contacto informante</th>

                                <th>Fecha</th>

                                <?php if ($rol === 'ADMIN'): ?>
                                    <th>Responsable</th>
                                <?php endif; ?>

                                <th>Acciones</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($visitas as $visita): ?>

                            <tr>

                                <td>

                                    <span class="codigo-visita">
                                        <?= htmlspecialchars($visita['codigo_visita']) ?>
                                    </span>

                                </td>


                                <td>

                                    <div class="institucion">

                                        <strong>
                                            <?= htmlspecialchars($visita['institucion']) ?>
                                        </strong>

                                        <?php if (!empty($visita['dependencia'])): ?>

                                            <small>
                                                <?= htmlspecialchars($visita['dependencia']) ?>
                                            </small>

                                        <?php endif; ?>

                                    </div>

                                </td>


                                <td>
                                    <?= htmlspecialchars($visita['tipo_institucion'] ?? '-') ?>
                                </td>


                                <td>

                                    <span class="badge-estado">
                                        <?= htmlspecialchars($visita['estado_venezuela'] ?? '-') ?>
                                    </span>

                                </td>


                                <td>
                                    <?= htmlspecialchars($visita['ciudad'] ?? '-') ?>
                                </td>


                                <td>

                                    <div class="institucion">
                                        <strong><?= htmlspecialchars($visita['funcionario'] ?? '-') ?></strong>
                                        <?php if (!empty($visita['cargo_funcionario'])): ?>
                                            <small><?= htmlspecialchars($visita['cargo_funcionario']) ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($visita['contacto_funcionario'])): ?>
                                            <small><?= htmlspecialchars($visita['contacto_funcionario']) ?></small>
                                        <?php endif; ?>
                                    </div>

                                </td>


                                <td>

                                    <?php

                                    if (!empty($visita['fecha_visita'])) {

                                        echo date(
                                            'd/m/Y',
                                            strtotime($visita['fecha_visita'])
                                        );

                                    } else {

                                        echo '-';

                                    }

                                    ?>

                                </td>


                                <?php if ($rol === 'ADMIN'): ?>

                                    <td>

                                        <div class="responsable">

                                            <span class="mini-avatar">

                                                <?php

                                                $responsable =
                                                    $visita['responsable']
                                                    ?? 'Sin asignar';

                                                echo strtoupper(
                                                    substr(
                                                        trim($responsable),
                                                        0,
                                                        1
                                                    )
                                                );

                                                ?>

                                            </span>

                                            <span>
                                                <?= htmlspecialchars($responsable) ?>
                                            </span>

                                        </div>

                                    </td>

                                <?php endif; ?>


                                <td>

                                    <div class="acciones-tabla">

                                        <a
                                            href="ver.php?id=<?= (int)$visita['id'] ?>"
                                            class="btn-tabla btn-ver"
                                        >
                                            Ver
                                        </a>

                                        <a
                                            href="editar.php?id=<?= (int)$visita['id'] ?>"
                                            class="btn-tabla btn-editar"
                                        >
                                            Editar
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="sin-datos visitas-vacio">

                    <div class="sin-datos-icono">
                        ▣
                    </div>

                    <?php if ($buscar !== ''): ?>

                        <strong>
                            No encontramos visitas
                        </strong>

                        <p>
                            No existen registros que coincidan con
                            "<b><?= htmlspecialchars($buscar) ?></b>".
                        </p>

                        <a href="visitas.php">
                            Ver todas las visitas
                        </a>

                    <?php else: ?>

                        <strong>
                            Aún no hay visitas registradas
                        </strong>

                        <p>
                            Comience registrando la primera visita institucional.
                        </p>

                        <a href="nueva.php">
                            Registrar primera visita
                        </a>

                    <?php endif; ?>

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
<script src="https://unpkg.com/dexie/dist/dexie.js"></script>
<script>
(function () {
    // Inicializar IndexedDB
    const db = new Dexie('NexoOfflineDB');
    db.version(1).stores({
        visitasPendientes: '++id, fecha_creacion, institucion, estado_venezuela, datos, archivos, fotos'
    });

    async function RenderizarVisitasLocales() {
        const pendientes = await db.visitasPendientes.toArray();
        if (pendientes.length === 0) return;

        const tbody = document.querySelector('.tabla-visitas tbody');
        const contadorSpan = document.querySelector('.visitas-toolbar span');
        
        // Si la tabla no existía (ej. pantalla de sin datos), la construimos dinámicamente
        if (!tbody) {
            const contenedor = document.querySelector('.panel-visitas');
            if (contenedor) {
                contenedor.innerHTML = `
                    <div class="tabla-contenedor">
                        <table class="tabla-visitas">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Institución</th>
                                    <th>Tipo</th>
                                    <th>Estado</th>
                                    <th>Ciudad</th>
                                    <th>Contacto informante</th>
                                    <th>Fecha</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                `;
            }
        }

        const targetTbody = document.querySelector('.tabla-visitas tbody');
        
        // Iterar en orden inverso para mostrar la más reciente primero
        pendientes.reverse().forEach(item => {
            const tr = document.createElement('tr');
            tr.className = 'visita-local-pendiente';
            tr.style.backgroundColor = '#fff8e6'; // Color amarillo/advertencia suave

            const fechaVisita = item.datos.fecha_visita 
                ? item.datos.fecha_visita.split('-').reverse().join('/') 
                : '-';

            tr.innerHTML = `
                <td>
                    <span class="codigo-visita" style="background:#f59e0b; color:#fff;">
                        PENDIENTE RED
                    </span>
                </td>
                <td>
                    <div class="institucion">
                        <strong>${item.institucion || 'Sin nombre'}</strong>
                        ${item.datos.dependencia ? `<small>${item.datos.dependencia}</small>` : ''}
                    </div>
                </td>
                <td>${item.datos.tipo_institucion || '-'}</td>
                <td>
                    <span class="badge-estado">${item.estado_venezuela || '-'}</span>
                </td>
                <td>${item.datos.ciudad || '-'}</td>
                <td>
                    <div class="institucion">
                        <strong>${item.datos.funcionario || '-'}</strong>
                        ${item.datos.cargo_funcionario ? `<small>${item.datos.cargo_funcionario}</small>` : ''}
                    </div>
                </td>
                <td>${fechaVisita}</td>
                <td>
                    <span style="font-size: 0.85rem; color: #d97706; font-weight: 600;">
                        En espera de conexión
                    </span>
                </td>
            `;
            
            if (targetTbody) {
                targetTbody.prepend(tr);
            }
        });

        // Actualizar el contador de registros en la barra de herramientas
        if (contadorSpan) {
            const totalActual = document.querySelectorAll('.tabla-visitas tbody tr').length;
            contadorSpan.textContent = `${totalActual} ${totalActual === 1 ? 'registro' : 'registros'} (${pendientes.length} sin sincronizar)`;
        }
    }

    document.addEventListener('DOMContentLoaded', RenderizarVisitasLocales);
})();
</script>
</body>

</html>