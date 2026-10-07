<?php

session_start();

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

require_once "config.php";

/* =====================================================
   INFORMACIÓN DEL USUARIO
===================================================== */

$nombreUsuario = $_SESSION["nombre"];
$rolUsuario = $_SESSION["rol"];
$usuarioId = $_SESSION["usuario_id"];


/* =====================================================
   CONTADORES
===================================================== */

if ($rolUsuario === "ADMIN") {

    $stmt = $pdo->query("SELECT COUNT(*) FROM visitas");
    $totalVisitas = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM evidencias");
    $totalEvidencias = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM archivos");
    $totalArchivos = $stmt->fetchColumn();

} else {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM visitas
        WHERE investigador_id = ?
    ");
    $stmt->execute([$usuarioId]);
    $totalVisitas = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM evidencias e
        INNER JOIN visitas v ON e.visita_id = v.id
        WHERE v.investigador_id = ?
    ");
    $stmt->execute([$usuarioId]);
    $totalEvidencias = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM archivos a
        INNER JOIN visitas v ON a.visita_id = v.id
        WHERE v.investigador_id = ?
    ");
    $stmt->execute([$usuarioId]);
    $totalArchivos = $stmt->fetchColumn();
}


/* =====================================================
   ÚLTIMAS VISITAS
===================================================== */

if ($rolUsuario === "ADMIN") {

    $stmt = $pdo->query("
        SELECT 
            v.*,
            u.nombre AS responsable
        FROM visitas v
        INNER JOIN usuarios u
            ON v.investigador_id = u.id
        ORDER BY v.fecha_visita DESC, v.id DESC
        LIMIT 5
    ");

} else {

    $stmt = $pdo->prepare("
        SELECT 
            v.*,
            u.nombre AS responsable
        FROM visitas v
        INNER JOIN usuarios u
            ON v.investigador_id = u.id
        WHERE v.investigador_id = ?
        ORDER BY v.fecha_visita DESC, v.id DESC
        LIMIT 5
    ");

    $stmt->execute([$usuarioId]);
}

$ultimasVisitas = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | NEXO</title>

    <link rel="stylesheet" href="css/estilos.css">

</head>

<body>



<div class="app">

    <button class="menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <div class="menu-overlay"></div>

    <!-- =================================================
         SIDEBAR
    ================================================== -->

    <aside class="sidebar">

        <div class="logo">

            <img src="logo2.png" alt="NEXO" class="logo-sidebar">

        </div>

        <nav class="menu">

            <a href="index.php" class="menu-item activo">
                <span class="icono">⌂</span>
                <span>Inicio</span>
            </a>

            <a href="visitas/visitas.php" class="menu-item">
                <span class="icono">▣</span>
                <span>Visitas</span>
            </a>

            <a href="evidencias/evidencias.php" class="menu-item">
                <span class="icono">▧</span>
                <span>Evidencias fotográficas</span>
            </a>

            <a href="archivos/archivos.php" class="menu-item">
                <span class="icono">□</span>
                <span>Archivos</span>
            </a>

            <a href="reportes.php" class="menu-item">
                <span class="icono">▥</span>
                <span>Reportes</span>
            </a>


            <?php if ($rolUsuario === "ADMIN"): ?>

                <div class="menu-separador"></div>

                <a href="usuarios.php" class="menu-item">
                    <span class="icono">♙</span>
                    <span>Usuarios</span>
                </a>

            <?php endif; ?>

            <?php if ($rolUsuario): ?>
                <a href="equipos.php" class="menu-item">
                    <span class="icono">◎</span>
                    <span>Equipos y capitales</span>
                </a>
                <a href="cobertura.php" class="menu-item">
                    <span class="icono">◉</span>
                    <span>Cobertura territorial</span>
                </a>
            <?php endif; ?>

        </nav>


        <div class="sidebar-footer">

            <div class="usuario">

                <div class="usuario-avatar">
                    <?= strtoupper(substr($nombreUsuario, 0, 1)) ?>
                </div>

                <div class="usuario-info">

                    <strong>
                        <?= htmlspecialchars($nombreUsuario) ?>
                    </strong>

                    <small>
                        <?= $rolUsuario === "ADMIN"
                            ? "Administrador"
                            : "Responsable del levantamiento"
                        ?>
                    </small>

                    <small class="usuario-equipo">
                        Equipo: <?= htmlspecialchars($_SESSION['equipo_nombre'] ?? 'Sin equipo asignado') ?>
                    </small>

                </div>

            </div>


            <a href="logout.php" class="cerrar-sesion">
                <span>↪</span>
                Cerrar sesión
            </a>

        </div>

    </aside>


    <!-- =================================================
         CONTENIDO
    ================================================== -->

    <main class="contenido">

        <!-- HEADER -->

        <header class="header">

            <div>

                <div class="breadcrumb">
                    NEXO / Inicio
                </div>

                <h1>Panel principal</h1>

                <p>
                    Gestión del levantamiento de información
                </p>

            </div>

            <div class="fecha">

                <?= date("d/m/Y") ?>

            </div>

        </header>


        <!-- BIENVENIDA -->

        <section class="bienvenida">

            <div>

                <span class="saludo">
                    Bienvenido
                </span>

                <h2>
                    <?= htmlspecialchars($nombreUsuario) ?>
                </h2>

                <p>
                    Desde este panel puede registrar y consultar
                    la información recopilada durante las visitas.
                </p>

            </div>

            <a href="visitas/nueva.php" class="btn-principal">
                + Nueva visita
            </a>

        </section>


        <!-- ESTADÍSTICAS -->

        <section class="estadisticas estadisticas-principales">

            <div class="estadistica">

                <div class="estadistica-icono azul">
                    ▣
                </div>

                <div>

                    <span>Visitas</span>

                    <strong>
                        <?= $totalVisitas ?>
                    </strong>

                </div>

            </div>


            <div class="estadistica">

                <div class="estadistica-icono naranja">
                    ▧
                </div>

                <div>

                    <span>Evidencias fotográficas</span>

                    <strong>
                        <?= $totalEvidencias ?>
                    </strong>

                </div>

            </div>


            <div class="estadistica">

                <div class="estadistica-icono morado">
                    □
                </div>

                <div>

                    <span>Archivos</span>

                    <strong>
                        <?= $totalArchivos ?>
                    </strong>

                </div>

            </div>

        </section>


        <!-- CONTENIDO INFERIOR -->

        <section class="dashboard-grid">


            <!-- ÚLTIMAS VISITAS -->

            <div class="panel">

                <div class="panel-header">

                    <div>

                        <h2>Últimas visitas</h2>

                        <p>
                            Registro de visitas realizadas
                        </p>

                    </div>

                    <a href="visitas/visitas.php">
                        Ver todas
                    </a>

                </div>


                <?php if (count($ultimasVisitas) > 0): ?>

                    <div class="tabla-contenedor">

                        <table>

                            <thead>

                                <tr>

                                    <th>Código</th>

                                    <th>Institución</th>

                                    <th>Ciudad</th>

                                    <th>Fecha</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($ultimasVisitas as $visita): ?>

                                    <tr>

                                        <td>

                                            <strong>
                                                <?= htmlspecialchars($visita["codigo_visita"]) ?>
                                            </strong>

                                        </td>

                                        <td>
                                            <?= htmlspecialchars($visita["institucion"]) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($visita["ciudad"] ?? "-") ?>
                                        </td>

                                        <td>
                                            <?= date(
                                                "d/m/Y",
                                                strtotime($visita["fecha_visita"])
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="sin-datos">

                        <div class="sin-datos-icono">
                            ▣
                        </div>

                        <strong>
                            No hay visitas registradas
                        </strong>

                        <p>
                            Comience registrando la primera visita.
                        </p>

                        <a href="visitas/nueva.php">
                            Registrar visita
                        </a>

                    </div>

                <?php endif; ?>

            </div>


            <!-- ACCIONES -->

            <div class="panel">

                <div class="panel-header">

                    <div>

                        <h2>Accesos directos</h2>

                        <p>
                            Módulos disponibles
                        </p>

                    </div>

                </div>


                <div class="acciones">

                    <a href="visitas/nueva.php" class="accion">

                        <span>▣</span>

                        <div>

                            <strong>Nueva visita</strong>

                            <small>
                                Registrar una visita institucional
                            </small>

                        </div>

                    </a>


                    <a href="evidencias/evidencias.php" class="accion">

                        <span>▧</span>

                        <div>

                            <strong>Evidencias fotográficas</strong>

                            <small>
                                Consultar fotografías cargadas
                            </small>

                        </div>

                    </a>


                    <a href="archivos/archivos.php" class="accion">

                        <span>□</span>

                        <div>

                            <strong>Archivos</strong>

                            <small>
                                Consultar documentos cargados
                            </small>

                        </div>

                    </a>

                </div>

            </div>

        </section>

    </main>

</div>

<script>
    (function () {
        var app = document.querySelector('.app');
        var toggle = document.querySelector('.menu-toggle');
        var overlay = document.querySelector('.menu-overlay');
        if (!app || !toggle || !overlay) return;
        function cerrarMenu() { app.classList.remove('menu-abierto'); toggle.setAttribute('aria-expanded', 'false'); }
        toggle.addEventListener('click', function () { var abierto = app.classList.toggle('menu-abierto'); toggle.setAttribute('aria-expanded', abierto ? 'true' : 'false'); });
        overlay.addEventListener('click', cerrarMenu);
        app.querySelectorAll('.menu-item').forEach(function (enlace) { enlace.addEventListener('click', cerrarMenu); });
    }());
</script>
<!-- Librería Dexie.js para IndexedDB -->
<script src="https://unpkg.com/dexie/dist/dexie.js"></script>

<!-- Script de control Offline y Sincronización -->
<script>
    // 1. Guardar información de sesión localmente
    localStorage.setItem('nexo_user_id', '<?= $usuarioId ?>');
    localStorage.setItem('nexo_user_role', '<?= $rolUsuario ?>');

    // 2. Registrar el Service Worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.log('Service Worker de NEXO registrado'))
            .catch(err => console.error('Error al registrar SW:', err));
    }

    // 3. Indicador visual de estado de red
    function evaluarRed() {
        if (!navigator.onLine) {
            console.warn('Trabajando en modo OFFLINE. Los datos se guardarán localmente.');
            document.body.classList.add('modo-offline');
        } else {
            document.body.classList.remove('modo-offline');
        }
    }
    window.addEventListener('online', evaluarRed);
    window.addEventListener('offline', evaluarRed);
    evaluarRed();
</script>
</body>

</html>