<?php

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once "../config.php";
require_once "../csrf.php";

$usuario_id = $_SESSION['usuario_id'];
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? '';

$mensaje = '';
$error = '';

$inicial = strtoupper(substr(trim($nombre), 0, 1));

$estados_equipo = [];
if ($rol === 'ADMIN') {
    $stmt_estados = $pdo->query(
        "SELECT c.id, c.estado, c.capital, c.gobernacion,
                COUNT(DISTINCT v.id) AS visitas_atendidas
         FROM capitales c
         LEFT JOIN visitas v ON v.estado_venezuela = c.estado
         WHERE c.estado_activo = 1
         GROUP BY c.id, c.estado, c.capital, c.gobernacion
         ORDER BY c.estado"
    );
    $estados_equipo = $stmt_estados->fetchAll();
} else {
    $stmt_estados = $pdo->prepare(
        "SELECT c.id, c.estado, c.capital, c.gobernacion,
                COUNT(DISTINCT v.id) AS visitas_atendidas
         FROM equipo_usuarios eu
         INNER JOIN asignaciones_capitales ac ON ac.equipo_id = eu.equipo_id
         INNER JOIN capitales c ON c.id = ac.capital_id
         LEFT JOIN equipo_usuarios eu_visitas ON eu_visitas.equipo_id = eu.equipo_id
         LEFT JOIN visitas v ON v.estado_venezuela = c.estado
                            AND v.investigador_id = eu_visitas.usuario_id
         WHERE eu.usuario_id = :usuario_id
           AND c.estado_activo = 1
         GROUP BY c.id, c.estado, c.capital, c.gobernacion
         ORDER BY c.estado"
    );
    $stmt_estados->execute([':usuario_id' => $usuario_id]);
    $estados_equipo = $stmt_estados->fetchAll();
}

$estados_permitidos = array_column($estados_equipo, 'estado');
$instituciones_gobernacion = array_values(array_unique(array_filter(array_column($estados_equipo, 'gobernacion'))));

$instituciones_visitadas = $pdo->query(
    "SELECT DISTINCT LOWER(TRIM(institucion))
     FROM visitas
     WHERE institucion IS NOT NULL AND TRIM(institucion) <> ''"
)->fetchAll(PDO::FETCH_COLUMN);

$instituciones_visitadas = array_fill_keys($instituciones_visitadas, true);
$instituciones_por_estado = [];
foreach ($estados_equipo as $estado_equipo) {
    $institucion = trim($estado_equipo['gobernacion'] ?? '');
    if ($institucion === '' || isset($instituciones_visitadas[mb_strtolower($institucion, 'UTF-8')])) {
        continue;
    }

    $instituciones_por_estado[$institucion] = $estado_equipo['estado'];
}
$instituciones_gobernacion = array_keys($instituciones_por_estado);

function nombreCarpetaSegura(string $nombre): string
{
    $nombre = trim($nombre);
    $nombre = preg_replace('/[^\pL\pN._() -]+/u', '-', $nombre) ?: 'visita';
    $nombre = preg_replace('/-+/', '-', $nombre);
    $nombre = trim($nombre, '.-_');

    return function_exists('mb_substr')
        ? mb_substr($nombre, 0, 140, 'UTF-8')
        : substr($nombre, 0, 140);
}

function categoriasCarpetasAdjuntos(): array
{
    return [
        'INFORMACION_DEMOGRAFICA' => 'Información demográfica',
        'INFORMACION_SOCIAL' => 'Información social',
        'DATOS_ECONOMICOS' => 'Datos económicos (unidades productivas)',
        'OTROS' => 'Otros'
    ];
}

function crearEstructuraVisita(string $estado, string $institucion, string $codigo_visita): array
{
    $nombre_estado = nombreCarpetaSegura($estado);
    $nombre_visita = nombreCarpetaSegura($institucion . ' - ' . $codigo_visita);
    $directorio_estado = __DIR__ . '/../uploads/visitas/' . $nombre_estado;
    $ruta_estado = 'uploads/visitas/' . $nombre_estado . '/';
    $directorios = [];

    foreach (categoriasCarpetasAdjuntos() as $categoria => $nombre_categoria) {
        $nombre_categoria_seguro = nombreCarpetaSegura($nombre_categoria);
        $directorio_categoria = $directorio_estado . DIRECTORY_SEPARATOR . $nombre_categoria_seguro;
        $directorio_visita = $directorio_categoria . DIRECTORY_SEPARATOR . $nombre_visita;

        foreach ([$directorio_categoria, $directorio_visita] as $directorio) {
            if (!is_dir($directorio) && !mkdir($directorio, 0755, true) && !is_dir($directorio)) {
                throw new RuntimeException('No fue posible crear las carpetas para guardar los archivos de la visita.');
            }
        }

        $directorios[$categoria] = [
            'directorio' => $directorio_visita,
            'ruta' => $ruta_estado . $nombre_categoria_seguro . '/' . $nombre_visita . '/'
        ];
    }

    return $directorios;
}

function guardarAdjuntosVisita(
    PDO $pdo,
    int $visita_id,
    int $usuario_id,
    string $estado,
    string $institucion,
    string $codigo_visita
): void
{
    $campos = [
        'archivos_demograficos' => 'INFORMACION_DEMOGRAFICA',
        'archivos_sociales' => 'INFORMACION_SOCIAL',
        'archivos_economicos' => 'DATOS_ECONOMICOS',
        'archivos_otros' => 'OTROS',
        'archivos' => 'OTROS',
        'fotos' => null
    ];
    $directorios_categoria = crearEstructuraVisita($estado, $institucion, $codigo_visita);
    // Lista blanca: solo se permite subir estas extensiones.
    // Cualquier otra (incluidas las que no imaginamos hoy) se rechaza.
    $extensiones_permitidas = [
        // Documentos e imágenes de oficina
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'csv', 'txt', 'rtf', 'odt', 'ods',
        // Imágenes / evidencias fotográficas
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $stmt_evidencia = $pdo->prepare(
        'INSERT INTO evidencias
            (visita_id, tipo_evidencia, nombre_archivo, ruta_archivo,
             fecha_captura, usuario_id)
         VALUES
            (:visita_id, :tipo_evidencia, :nombre_archivo, :ruta_archivo,
             NOW(), :usuario_id)'
    );
    $stmt_archivo = $pdo->prepare(
        'INSERT INTO archivos
            (visita_id, nombre_original, nombre_servidor, ruta,
             extension, tipo_mime, categoria, tamaño, usuario_id)
         VALUES
            (:visita_id, :nombre_original, :nombre_servidor, :ruta,
             :extension, :tipo_mime, :categoria, :tamano, :usuario_id)'
    );

    foreach ($campos as $campo => $categoria) {
        if (empty($_FILES[$campo]['name']) || !is_array($_FILES[$campo]['name'])) {
            continue;
        }

        foreach ($_FILES[$campo]['name'] as $indice => $nombre_original) {
            $error_archivo = $_FILES[$campo]['error'][$indice];

            if ($error_archivo === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error_archivo !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Uno de los archivos no pudo cargarse.');
            }

            $ruta_temporal = $_FILES[$campo]['tmp_name'][$indice];
            $tamaño = (int) $_FILES[$campo]['size'][$indice];
            $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

            if ($tamaño > 25 * 1024 * 1024) {
                throw new RuntimeException('Cada archivo debe pesar como máximo 25 MB.');
            }

            if ($extension === '' || !in_array($extension, $extensiones_permitidas, true)) {
                throw new RuntimeException('El tipo de archivo seleccionado no está permitido. Formatos aceptados: ' . implode(', ', $extensiones_permitidas) . '.');
            }

            $tipo_mime = $finfo->file($ruta_temporal) ?: 'application/octet-stream';
            $nombre_servidor = bin2hex(random_bytes(16));

            if ($extension !== '') {
                $nombre_servidor .= '.' . $extension;
            }

            $categoria_adjunto = $categoria ?? 'OTROS';
            $directorio_adjunto = $directorios_categoria[$categoria_adjunto];
            $ruta_servidor = $directorio_adjunto['directorio'] . DIRECTORY_SEPARATOR . $nombre_servidor;
            $ruta_relativa = $directorio_adjunto['ruta'] . $nombre_servidor;

            if (!move_uploaded_file($ruta_temporal, $ruta_servidor)) {
                throw new RuntimeException('No fue posible guardar uno de los archivos.');
            }

            if ($campo === 'fotos') {
                $stmt_evidencia->execute([
                    ':visita_id' => $visita_id,
                    ':tipo_evidencia' => 'FOTOGRAFIA',
                    ':nombre_archivo' => $nombre_original,
                    ':ruta_archivo' => $ruta_relativa,
                    ':usuario_id' => $usuario_id
                ]);
            } else {
                $stmt_archivo->execute([
                    ':visita_id' => $visita_id,
                    ':nombre_original' => $nombre_original,
                    ':nombre_servidor' => $nombre_servidor,
                    ':ruta' => $ruta_relativa,
                    ':extension' => $extension !== '' ? $extension : null,
                    ':tipo_mime' => $tipo_mime,
                    ':categoria' => $categoria_adjunto,
                    ':tamano' => $tamaño,
                    ':usuario_id' => $usuario_id
                ]);
            }
        }
    }
}


/* =========================================================
   GUARDAR VISITA
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_validar();

    $tipo_institucion = trim($_POST['tipo_institucion'] ?? '');
    $estado_venezuela = trim($_POST['estado_venezuela'] ?? '');
    $ciudad = trim($_POST['ciudad'] ?? '');
    $institucion_seleccionada = trim($_POST['institucion'] ?? '');
    $institucion_otra = trim($_POST['institucion_otra'] ?? '');
    $institucion = $institucion_seleccionada === '__OTRA__'
        ? $institucion_otra
        : $institucion_seleccionada;
    $dependencia = trim($_POST['dependencia'] ?? '');
    $fecha_visita = trim($_POST['fecha_visita'] ?? '');
    $funcionario = trim($_POST['funcionario'] ?? '');
    $cargo_funcionario = trim($_POST['cargo_funcionario'] ?? '');
    $contacto_funcionario = trim($_POST['contacto_funcionario'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');


    /* =====================================================
       VALIDACIONES
       ===================================================== */

    if ($institucion === '') {
        $error = 'Debe ingresar el nombre de la institución.';
    }

    elseif ($tipo_institucion === '') {
        $error = 'Debe seleccionar el tipo de institución.';
    }

    elseif ($estado_venezuela === '') {
        $error = 'Debe seleccionar un estado asignado a su equipo.';
    }

    elseif (!in_array($estado_venezuela, $estados_permitidos, true)) {
        $error = 'El estado seleccionado no está asignado a su equipo.';
    }

    elseif ($ciudad === '') {
        $error = 'Debe ingresar la ciudad.';
    }

    elseif ($fecha_visita === '') {
        $error = 'Debe indicar la fecha de la visita.';
    }

    if ($error === '') {
        if ($institucion_seleccionada !== '__OTRA__') {
            if (!isset($instituciones_por_estado[$institucion])) {
                $error = 'La institución seleccionada no está disponible para una nueva visita.';
            } elseif ($instituciones_por_estado[$institucion] !== $estado_venezuela) {
                $error = 'El estado no corresponde a la institución seleccionada.';
            }
        }
    }

    if ($error === '') {
        foreach (['archivos_demograficos', 'archivos_sociales', 'archivos_economicos', 'archivos_otros', 'archivos', 'fotos'] as $campo_archivos) {
            if (!isset($_FILES[$campo_archivos]['error']) || !is_array($_FILES[$campo_archivos]['error'])) {
                continue;
            }

            foreach ($_FILES[$campo_archivos]['error'] as $error_archivo) {
                if ($error_archivo === UPLOAD_ERR_INI_SIZE || $error_archivo === UPLOAD_ERR_FORM_SIZE) {
                    $error = 'El hosting rechazó uno de los archivos por superar el límite permitido.';
                    break 2;
                }
            }
        }
    }

    if ($error === '') {
        $stmt_institucion = $pdo->prepare(
            'SELECT COUNT(*) FROM visitas
             WHERE LOWER(TRIM(institucion)) = LOWER(TRIM(:institucion))'
        );
        $stmt_institucion->execute([':institucion' => $institucion]);

        if ((int) $stmt_institucion->fetchColumn() > 0) {
            $error = 'La institución seleccionada ya tiene una visita registrada.';
        }
    }


    /* =====================================================
       INSERTAR
       ===================================================== */

    if ($error === '') {

        try {

            $pdo->beginTransaction();

            do {
                $codigo_visita = 'VIS-' . date('YmdHis') . '-' . random_int(1000, 9999);
                $stmt_codigo = $pdo->prepare(
                    'SELECT COUNT(*) FROM visitas WHERE codigo_visita = :codigo_visita'
                );
                $stmt_codigo->execute([':codigo_visita' => $codigo_visita]);
            } while ((int) $stmt_codigo->fetchColumn() > 0);


            $sql = "
                INSERT INTO visitas (
                    codigo_visita,
                    tipo_institucion,
                    estado_venezuela,
                    ciudad,
                    institucion,
                    dependencia,
                    fecha_visita,
                    funcionario,
                    cargo_funcionario,
                    contacto_funcionario,
                    investigador_id,
                    observaciones,
                    fecha_creacion
                )
                VALUES (
                    :codigo_visita,
                    :tipo_institucion,
                    :estado_venezuela,
                    :ciudad,
                    :institucion,
                    :dependencia,
                    :fecha_visita,
                    :funcionario,
                    :cargo_funcionario,
                    :contacto_funcionario,
                    :investigador_id,
                    :observaciones,
                    NOW()
                )
            ";


            $stmt = $pdo->prepare($sql);


            $stmt->execute([

                ':codigo_visita' => $codigo_visita,

                ':tipo_institucion' => $tipo_institucion,

                ':estado_venezuela' => $estado_venezuela,

                ':ciudad' => $ciudad,

                ':institucion' => $institucion,

                ':dependencia' => $dependencia,

                ':fecha_visita' => $fecha_visita,

                ':funcionario' => $funcionario,

                ':cargo_funcionario' => $cargo_funcionario,

                ':contacto_funcionario' => $contacto_funcionario,

                ':investigador_id' => $usuario_id,

                ':observaciones' => $observaciones

            ]);

            guardarAdjuntosVisita(
                $pdo,
                (int) $pdo->lastInsertId(),
                $usuario_id,
                $estado_venezuela,
                $institucion,
                $codigo_visita
            );

            $pdo->commit();


            /*
             * Después de guardar correctamente,
             * regresamos al listado de visitas.
             */

            header("Location: visitas.php?guardado=1");
            exit;


        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('NEXO - error al guardar visita: ' . $e->getMessage());

            $error = 'No fue posible guardar la visita. Intente de nuevo o contacte al administrador.';

        } catch (RuntimeException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('NEXO - error inesperado al guardar visita: ' . $e->getMessage());
            $error = 'Ocurrió un error del servidor al guardar la visita. Revise el registro de errores del hosting.';

        }

    }

}

?>

<!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#002f6c">

    <title>Nueva visita | NEXO</title>


    <!-- CSS GENERAL -->

    <link
        rel="stylesheet"
        href="../css/estilos.css"
    >


    <!-- CSS DE VISITAS -->

    <link
        rel="stylesheet"
        href="../css/visitas.css"
    >

</head>


<body>


<div class="app">

    <button class="menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <div class="menu-overlay"></div>


    <!-- =====================================================
         SIDEBAR
         ===================================================== -->

    <aside class="sidebar">


        <div class="logo">

            <img src="../logo2.png" alt="NEXO" class="logo-sidebar">

        </div>


        <nav class="menu">


            <a
                href="../index.php"
                class="menu-item"
            >

                <span class="icono">
                    ⌂
                </span>

                <span>
                    Inicio
                </span>

            </a>


            <a
                href="visitas.php"
                class="menu-item activo"
            >

                <span class="icono">
                    ▣
                </span>

                <span>
                    Visitas
                </span>

            </a>


            <a
                href="../evidencias/evidencias.php"
                class="menu-item"
            >

                <span class="icono">
                    ▧
                </span>

                <span>
                    Evidencias fotográficas
                </span>

            </a>


            <a
                href="../archivos/archivos.php"
                class="menu-item"
            >

                <span class="icono">
                    □
                </span>

                <span>
                    Archivos
                </span>

            </a>


            <a
                href="../reportes.php"
                class="menu-item"
            >

                <span class="icono">
                    ▥
                </span>

                <span>
                    Reportes
                </span>

            </a>


            <?php if ($rol === 'ADMIN'): ?>

                <div class="menu-separador"></div>

                <a
                    href="../usuarios.php"
                    class="menu-item"
                >

                    <span class="icono">
                        ♙
                    </span>

                    <span>
                        Usuarios
                    </span>

                </a>

            <?php endif; ?>


        </nav>


        <!-- =================================================
             USUARIO
             ================================================= -->

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


            <a
                href="../logout.php"
                class="cerrar-sesion"
            >

                <span>
                    ↪
                </span>

                Cerrar sesión

            </a>


        </div>


    </aside>



    <!-- =====================================================
         CONTENIDO
         ===================================================== -->

    <main class="contenido">


        <!-- ENCABEZADO -->

        <header class="header">

            <div>

                <div class="breadcrumb">

                    NEXO / Visitas / Nueva visita

                </div>


                <h1>

                    Nueva visita

                </h1>


                <p>

                    Registre la información obtenida durante la visita institucional.

                </p>

            </div>


            <div class="fecha">

                <?= date('d/m/Y') ?>

            </div>

        </header>



        <!-- =================================================
             MENSAJE DE ERROR
             ================================================= -->

        <?php if ($error !== ''): ?>

            <div class="alerta alerta-error">

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>

        <div class="offline-estado" data-offline-estado hidden></div>
        <button type="button" class="btn-secundario offline-sincronizar" data-sincronizar-visitas hidden>Sincronizar visitas</button>



        <!-- =================================================
             FORMULARIO
             ================================================= -->

        <section class="panel formulario-visita">


            <form
                method="POST"
                action="nueva.php"
                enctype="multipart/form-data"
                data-visita-formulario
            >

                <?= csrf_campo() ?>


                <!-- =================================================
                     IDENTIFICACIÓN
                     ================================================= -->

                <div class="form-seccion">

                    <div class="form-seccion-titulo">

                        <h2>
                            Identificación de la visita
                        </h2>

                        <p>
                            Información básica de la visita realizada.
                        </p>

                    </div>


                    <div class="form-grid">


                        <div class="campo">

                            <label for="codigo_visita">

                                Código de visita

                            </label>

                            <input
                                type="text"
                                id="codigo_visita"
                                value="Se generará automáticamente"
                                readonly
                            >

                            <small>
                                El sistema asignará un código único al guardar.
                            </small>

                        </div>


                        <div class="campo">

                            <label for="fecha_visita">

                                Fecha de la visita
                                <span>*</span>

                            </label>

                            <input
                                type="date"
                                id="fecha_visita"
                                name="fecha_visita"
                                value="<?= htmlspecialchars($_POST['fecha_visita'] ?? date('Y-m-d')) ?>"
                                required
                            >

                        </div>

                    </div>

                </div>



                <!-- =================================================
                     INSTITUCIÓN
                     ================================================= -->

                <div class="form-seccion">

                    <div class="form-seccion-titulo">

                        <h2>
                            Institución visitada
                        </h2>

                        <p>
                            Registre dónde se realizó el levantamiento de información.
                        </p>

                    </div>


                    <div class="form-grid">


                        <div class="campo campo-completo">

                            <label for="institucion">

                                Nombre de la institución
                                <span>*</span>

                            </label>

                            <select
                                id="institucion"
                                name="institucion"
                                required
                                onchange="mostrarInstitucionPersonalizada(this)"
                            >
                                <option value="">Seleccione la institución...</option>
                                <?php foreach ($instituciones_gobernacion as $gobernacion): ?>
                                    <option
                                        value="<?= htmlspecialchars($gobernacion) ?>"
                                        data-estado="<?= htmlspecialchars($instituciones_por_estado[$gobernacion]) ?>"
                                        <?= (($_POST['institucion'] ?? '') === $gobernacion) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($gobernacion) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__OTRA__" <?= (($_POST['institucion'] ?? '') === '__OTRA__') ? 'selected' : '' ?>>Otra institución</option>
                            </select>

                            <input
                                type="text"
                                id="institucion_otra"
                                name="institucion_otra"
                                placeholder="Escriba el nombre de la institución"
                                value="<?= htmlspecialchars($_POST['institucion_otra'] ?? '') ?>"
                                style="display: none; margin-top: 8px;"
                            >

                        </div>


                        <div class="campo">

                            <label for="tipo_institucion">

                                Tipo de institución
                                <span>*</span>

                            </label>


                            <select
                                id="tipo_institucion"
                                name="tipo_institucion"
                                required
                            >

                                <option value="">
                                    Seleccione...
                                </option>

                                <option value="EMBAJADA"
                                    <?= (($_POST['tipo_institucion'] ?? '') === 'EMBAJADA') ? 'selected' : '' ?>>
                                    Embajada
                                </option>

                                <option value="CANCILLERIA"
                                    <?= (($_POST['tipo_institucion'] ?? '') === 'CANCILLERIA') ? 'selected' : '' ?>>
                                    Cancillería
                                </option>

                                <option value="GOBERNACION"
                                    <?= (($_POST['tipo_institucion'] ?? '') === 'GOBERNACION') ? 'selected' : '' ?>>
                                    Gobernación
                                </option>

                                <option value="OTRA"
                                    <?= (($_POST['tipo_institucion'] ?? '') === 'OTRA') ? 'selected' : '' ?>>
                                    Otra
                                </option>

                            </select>

                        </div>


                        <div class="campo">

                            <label for="estado_venezuela">

                                Estado de Venezuela
                                <span>*</span>

                            </label>

                            <select
                                id="estado_venezuela"
                                name="estado_venezuela"
                                required
                            >
                                <option value="">Seleccione un estado asignado...</option>
                                <?php foreach ($estados_equipo as $estado_equipo): ?>
                                    <option
                                        value="<?= htmlspecialchars($estado_equipo['estado']) ?>"
                                        data-estado="<?= htmlspecialchars($estado_equipo['estado']) ?>"
                                        <?= (($_POST['estado_venezuela'] ?? '') === $estado_equipo['estado']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($estado_equipo['estado']) ?> - <?= htmlspecialchars($estado_equipo['capital']) ?>
                                        (<?= (int) $estado_equipo['visitas_atendidas'] > 0 ? 'Atendida' : 'Pendiente' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <small>
                                Solo aparecen estados asignados a su equipo.
                            </small>

                        </div>


                        <div class="campo">

                            <label for="ciudad">

                                Ciudad
                                <span>*</span>

                            </label>

                            <input
                                type="text"
                                id="ciudad"
                                name="ciudad"
                                placeholder="Ejemplo: Maracaibo"
                                value="<?= htmlspecialchars($_POST['ciudad'] ?? '') ?>"
                                required
                            >

                        </div>


                        <div class="campo">

                            <label for="dependencia">

                                Dependencia / oficina

                            </label>

                            <input
                                type="text"
                                id="dependencia"
                                name="dependencia"
                                placeholder="Ejemplo: Oficina de Atención al Ciudadano"
                                value="<?= htmlspecialchars($_POST['dependencia'] ?? '') ?>"
                            >

                        </div>


                    </div>

                </div>



                <!-- =================================================
                     FUNCIONARIO
                     ================================================= -->

                <div class="form-seccion">

                    <div class="form-seccion-titulo">

                        <h2>
                            Persona que entregó la información
                        </h2>

                        <p>
                            Registre el nombre y cargo de la persona que suministró la información.
                        </p>

                    </div>


                    <div class="form-grid">


                        <div class="campo">

                            <label for="funcionario">

                                Contacto / nombre de la persona

                            </label>

                            <input
                                type="text"
                                id="funcionario"
                                name="funcionario"
                                placeholder="Nombre completo"
                                value="<?= htmlspecialchars($_POST['funcionario'] ?? '') ?>"
                            >

                        </div>


                        <div class="campo">

                            <label for="cargo_funcionario">

                                Cargo o función

                            </label>

                            <input
                                type="text"
                                id="cargo_funcionario"
                                name="cargo_funcionario"
                                placeholder="Cargo o función"
                                value="<?= htmlspecialchars($_POST['cargo_funcionario'] ?? '') ?>"
                            >

                        </div>

                        <div class="campo">

                            <label for="telefono_funcionario">
                                Número telefónico
                            </label>

                            <input
                                type="tel"
                                id="contacto_funcionario"
                                name="contacto_funcionario"
                                placeholder="Ejemplo: 0414-1234567"
                                value="<?= htmlspecialchars($_POST['contacto_funcionario'] ?? '') ?>"
                                autocomplete="tel"
                            >

                        </div>


                    </div>

                </div>



                <!-- =================================================
                     OBSERVACIONES
                     ================================================= -->

                <div class="form-seccion">

                    <div class="form-seccion-titulo">

                        <h2>
                            Observaciones
                        </h2>

                        <p>
                            Registre información relevante obtenida durante la visita.
                        </p>

                    </div>


                    <div class="campo campo-completo">

                        <label for="observaciones">

                            Observaciones

                        </label>


                        <textarea
                            id="observaciones"
                            name="observaciones"
                            rows="6"
                            placeholder="Describa los aspectos relevantes de la visita, información obtenida, compromisos, fuentes identificadas, etc."
                        ><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>

                    </div>

                </div>



                <!-- =================================================
                     ADJUNTOS
                     ================================================= -->

                <div class="form-seccion">

                    <div class="form-seccion-titulo">

                        <h2>
                            Archivos y evidencias fotográficas
                        </h2>

                        <p>
                            Clasifique los documentos de la visita y agregue evidencias fotográficas. Cada archivo puede pesar hasta 25 MB; este paso es opcional.
                        </p>

                    </div>


                    <div class="form-grid">
                        <div class="campo">
                            <label for="archivos-demograficos">Información demográfica</label>
                            <div class="carga-archivos" data-carga-archivos="demograficos" data-nombre-campo="archivos_demograficos[]">
                                <input type="file" id="archivos-demograficos" name="archivos_demograficos[]" multiple>
                            </div>
                        </div>
                        <div class="campo">
                            <label for="archivos-sociales">Información social</label>
                            <div class="carga-archivos" data-carga-archivos="sociales" data-nombre-campo="archivos_sociales[]">
                                <input type="file" id="archivos-sociales" name="archivos_sociales[]" multiple>
                            </div>
                        </div>
                        <div class="campo">
                            <label for="archivos-economicos">Datos económicos (unidades productivas)</label>
                            <div class="carga-archivos" data-carga-archivos="economicos" data-nombre-campo="archivos_economicos[]">
                                <input type="file" id="archivos-economicos" name="archivos_economicos[]" multiple>
                            </div>
                        </div>
                        <div class="campo">
                            <label for="archivos-otros">Otros</label>
                            <div class="carga-archivos" data-carga-archivos="otros" data-nombre-campo="archivos_otros[]">
                                <input type="file" id="archivos-otros" name="archivos_otros[]" multiple>
                            </div>
                        </div>

                        <div class="campo">
                            <label for="fotos">Evidencias fotográficas</label>
                            <div class="carga-archivos carga-fotos" data-carga-archivos="fotos" data-aceptar="image/*" data-captura="environment">
                            </div>
                            <small>En celulares podrá abrir la cámara o elegir fotos existentes.</small>
                        </div>
                    </div>

                </div>



                <!-- =================================================
                     INFORMACIÓN DEL RESPONSABLE
                     ================================================= -->

                <div class="responsable-formulario">

                    <div class="mini-avatar">

                        <?= htmlspecialchars($inicial) ?>

                    </div>


                    <div>

                        <strong>
                            Responsable del levantamiento
                        </strong>

                        <span>
                            <?= htmlspecialchars($nombre) ?>
                        </span>

                    </div>

                </div>



                <!-- =================================================
                     BOTONES
                     ================================================= -->

                <div class="form-acciones">


                    <a
                        href="visitas.php"
                        class="btn-cancelar"
                    >

                        Cancelar

                    </a>


                    <button
                        type="submit"
                        class="btn-principal"
                    >

                        Guardar visita

                    </button>


                </div>


            </form>


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

<script>
    function mostrarInstitucionPersonalizada(selector) {
        var campo = document.getElementById('institucion_otra');
        var esOtra = selector.value === '__OTRA__';
        campo.style.display = esOtra ? 'block' : 'none';
        campo.required = esOtra;
        if (!esOtra) campo.value = '';

        var estadoSelector = document.getElementById('estado_venezuela');
        var institucionSeleccionada = selector.options[selector.selectedIndex];
        var estadoInstitucion = institucionSeleccionada ? institucionSeleccionada.dataset.estado : '';

        Array.prototype.forEach.call(estadoSelector.options, function (opcion) {
            var esPlaceholder = opcion.value === '';
            opcion.hidden = !esOtra && !esPlaceholder && opcion.value !== estadoInstitucion;
            opcion.disabled = opcion.hidden;
        });

        if (!esOtra && estadoInstitucion !== '') {
            estadoSelector.value = estadoInstitucion;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var selector = document.getElementById('institucion');
        if (selector) mostrarInstitucionPersonalizada(selector);
    });
</script>

<script>
    document.querySelectorAll('[data-carga-archivos]').forEach(function (contenedor) {
        function prepararEntrada(entrada, grupo) {
            entrada.addEventListener('change', function () {
                if (!entrada.files.length || grupo.dataset.completado === 'true') return;
                grupo.dataset.completado = 'true';
                crearGrupo();
            });
        }

        function crearGrupo() {
            if (!contenedor.classList.contains('carga-fotos')) {
                var nuevaEntrada = document.createElement('input');
                nuevaEntrada.type = 'file';
                nuevaEntrada.multiple = true;
                nuevaEntrada.name = contenedor.dataset.nombreCampo || 'archivos[]';
                contenedor.appendChild(nuevaEntrada);
                prepararEntrada(nuevaEntrada, nuevaEntrada);
                return;
            }

            var grupo = document.createElement('div');
            grupo.className = 'foto-carga-grupo';
            var captura = document.createElement('label');
            captura.className = 'btn-carga-foto btn-carga-camara';
            captura.appendChild(document.createTextNode('Tomar foto'));
            var entradaCamara = document.createElement('input');
            entradaCamara.type = 'file'; entradaCamara.name = 'fotos[]'; entradaCamara.accept = 'image/*'; entradaCamara.setAttribute('capture', 'environment');
            captura.appendChild(entradaCamara);
            var seleccion = document.createElement('label');
            seleccion.className = 'btn-carga-foto btn-carga-archivo';
            seleccion.appendChild(document.createTextNode('Elegir imagen'));
            var entradaArchivo = document.createElement('input');
            entradaArchivo.type = 'file'; entradaArchivo.name = 'fotos[]'; entradaArchivo.accept = 'image/*';
            seleccion.appendChild(entradaArchivo);
            grupo.appendChild(captura); grupo.appendChild(seleccion); contenedor.appendChild(grupo);
            prepararEntrada(entradaCamara, grupo); prepararEntrada(entradaArchivo, grupo);
        }

        if (contenedor.classList.contains('carga-fotos')) crearGrupo();
        else prepararEntrada(contenedor.querySelector('input[type="file"]'), contenedor.querySelector('input[type="file"]'));
    });
</script>

<script>
    (function () {
        var formulario = document.querySelector('form[enctype="multipart/form-data"]');
        if (!formulario) return;

        function comprimirImagen(archivo) {
            if (!archivo.type || archivo.type.indexOf('image/') !== 0 || archivo.size < 400 * 1024) {
                return Promise.resolve(archivo);
            }

            return new Promise(function (resolver) {
                var imagen = new Image();
                var lector = new FileReader();
                var resuelto = false;
                var temporizador = setTimeout(function () {
                    if (!resuelto) {
                        resuelto = true;
                        resolver(archivo);
                    }
                }, 30000);
                function resolverUnaVez(valor) {
                    if (resuelto) return;
                    resuelto = true;
                    clearTimeout(temporizador);
                    resolver(valor);
                }
                lector.onload = function () { imagen.src = lector.result; };
                lector.onerror = function () { resolverUnaVez(archivo); };
                imagen.onload = function () {
                    var escala = Math.min(1, 2200 / Math.max(imagen.width, imagen.height));
                    var lienzo = document.createElement('canvas');
                    lienzo.width = Math.round(imagen.width * escala);
                    lienzo.height = Math.round(imagen.height * escala);
                    lienzo.getContext('2d').drawImage(imagen, 0, 0, lienzo.width, lienzo.height);
                    var esPng = archivo.type === 'image/png';
                    lienzo.toBlob(function (blob) {
                        var tipoSalida = esPng ? 'image/png' : 'image/jpeg';
                        var extensionSalida = esPng ? '.png' : '.jpg';
                        resolverUnaVez(blob && blob.size < archivo.size
                            ? new File([blob], archivo.name.replace(/\.[^.]+$/, extensionSalida), { type: tipoSalida })
                            : archivo);
                    }, esPng ? 'image/png' : 'image/jpeg', 0.78);
                };
                    imagen.onerror = function () { resolverUnaVez(archivo); };
                lector.readAsDataURL(archivo);
            });
        }

        async function prepararEnvio(evento) {
            if (formulario.dataset.optimizando === 'true') return;
            if (!navigator.onLine) return;
            evento.preventDefault();
            formulario.dataset.optimizando = 'true';
            var boton = formulario.querySelector('button[type="submit"]');
            var textoOriginal = boton ? boton.textContent : '';
            if (boton) { boton.disabled = true; boton.textContent = 'Preparando archivos...'; }

            var entradas = Array.from(formulario.querySelectorAll('input[type="file"][name="fotos[]"]'));
            await Promise.all(entradas.map(async function (entrada) {
                if (!entrada.files.length) return;
                var optimizada = await comprimirImagen(entrada.files[0]);
                if (optimizada !== entrada.files[0] && typeof DataTransfer !== 'undefined') {
                    var transferencia = new DataTransfer();
                    transferencia.items.add(optimizada);
                    entrada.files = transferencia.files;
                }
            }));

            if (boton) boton.textContent = textoOriginal;
            formulario.dataset.optimizando = 'enviado';
            formulario.removeEventListener('submit', prepararEnvio);
            window.nexoEnviarVisita(formulario);
        }

        formulario.addEventListener('submit', prepararEnvio);
    }());
</script>

<script src="../offline-visitas.js"></script>

<!-- Cargar Dexie.js para manejar IndexedDB fácil -->
<script src="https://unpkg.com/dexie/dist/dexie.js"></script>

<script>
// 1. Inicializar la base de datos local IndexedDB
const db = new Dexie('NexoOfflineDB');
db.version(1).stores({
    visitasPendientes: '++id, fecha_creacion, institucion, estado_venezuela, datos, archivos, fotos'
});

const formVisita = document.querySelector('[data-visita-formulario]');
const indicadorEstado = document.querySelector('[data-offline-estado]');
const btnSincronizar = document.querySelector('[data-sincronizar-visitas]');

// 2. Control Visual de Conexión
function actualizarIndicadorRed() {
    if (!navigator.onLine) {
        indicadorEstado.hidden = false;
        indicadorEstado.textContent = 'Modo sin conexión: Las visitas guardadas se sincronizarán cuando regrese el internet.';
        indicadorEstado.className = 'alerta alerta-advertencia';
    } else {
        indicadorEstado.hidden = true;
        sincronizarVisitasPendientes();
    }
}
window.addEventListener('online', actualizarIndicadorRed);
window.addEventListener('offline', actualizarEstadoRed);
actualizarIndicadorRed();

// 3. Helper para convertir FileList/Files a Blobs/ArrayBuffers para IndexedDB
async function procesarArchivosLocales(inputElement) {
    const archivosProcesados = [];
    if (!inputElement || !inputElement.files) return archivosProcesados;

    for (const file of inputElement.files) {
        const buffer = await file.arrayBuffer();
        archivosProcesados.push({
            nombre: file.name,
            tipo: file.type,
            tamano: file.size,
            contenido: buffer // Guardar binario directamente en IndexedDB
        });
    }
    return archivosProcesados;
}

// 4. Interceptar el envío del formulario
if (formVisita) {
    formVisita.addEventListener('submit', async function (e) {
        // Si NO hay conexión, guardamos localmente
        if (!navigator.onLine) {
            e.preventDefault();

            const formData = new FormData(formVisita);
            const inputArchivos = formVisita.querySelector('input[name="archivos[]"]');
            const inputFotos = formVisita.querySelector('input[name="fotos[]"]');

            const archivosBinarios = await procesarArchivosLocales(inputArchivos);
            const fotosBinarias = await procesarArchivosLocales(inputFotos);

            // Construir el objeto de la visita
            const visitaLocal = {
                fecha_creacion: new Date().toISOString(),
                institucion: formData.get('institucion') === '__OTRA__' 
                    ? formData.get('institucion_otra') 
                    : formData.get('institucion'),
                estado_venezuela: formData.get('estado_venezuela'),
                datos: {
                    tipo_institucion: formData.get('tipo_institucion'),
                    ciudad: formData.get('ciudad'),
                    dependencia: formData.get('dependencia'),
                    fecha_visita: formData.get('fecha_visita'),
                    funcionario: formData.get('funcionario'),
                    cargo_funcionario: formData.get('cargo_funcionario'),
                    contacto_funcionario: formData.get('contacto_funcionario'),
                    observaciones: formData.get('observaciones')
                },
                archivos: archivosBinarios,
                fotos: fotosBinarias
            };

            // Guardar en la base de datos interna del equipo
            await db.visitasPendientes.add(visitaLocal);

            alert('Visita e imágenes guardadas localmente en el PC. Se enviarán al servidor cuando vuelva el internet.');
            formVisita.reset();
            btnSincronizar.hidden = false;
        }
    });
}

// 5. Función para Sincronizar Automáticamente al Volver a Estar Online
async function sincronizarVisitasPendientes() {
    const pendientes = await db.visitasPendientes.toArray();
    if (pendientes.length === 0) {
        if (btnSincronizar) btnSincronizar.hidden = true;
        return;
    }

    console.log(`Sincronizando ${pendientes.length} visita(s) pendiente(s)...`);

    for (const item of pendientes) {
        try {
            const formData = new FormData();
            
            // Reconstruir campos de texto
            formData.append('tipo_institucion', item.datos.tipo_institucion);
            formData.append('estado_venezuela', item.estado_venezuela);
            formData.append('ciudad', item.datos.ciudad);
            formData.append('institucion', '__OTRA__');
            formData.append('institucion_otra', item.institucion);
            formData.append('dependencia', item.datos.dependencia);
            formData.append('fecha_visita', item.datos.fecha_visita);
            formData.append('funcionario', item.datos.funcionario);
            formData.append('cargo_funcionario', item.datos.cargo_funcionario);
            formData.append('contacto_funcionario', item.datos.contacto_funcionario);
            formData.append('observaciones', item.datos.observaciones);

            // Reconstruir archivos adjuntos desde Blob/ArrayBuffer
            item.archivos.forEach(arch => {
                const blob = new Blob([arch.contenido], { type: arch.tipo });
                formData.append('archivos[]', blob, arch.nombre);
            });

            item.fotos.forEach(foto => {
                const blob = new Blob([foto.contenido], { type: foto.tipo });
                formData.append('fotos[]', blob, foto.nombre);
            });

            // Enviar al PHP servidor
            const respuesta = await fetch('nueva.php', {
                method: 'POST',
                body: formData
            });

            if (respuesta.ok) {
                // Borrar del IndexedDB tras éxito
                await db.visitasPendientes.delete(item.id);
            }
        } catch (err) {
            console.error('Error al sincronizar visita:', err);
            break; // Detener bucle si se vuelve a perder la conexión
        }
    }

    const restantes = await db.visitasPendientes.count();
    if (restantes === 0) {
        alert('¡Sincronización completada! Todas las visitas locales se guardaron en el servidor.');
        window.location.reload();
    }
}

// Botón manual de sincronización
if (btnSincronizar) {
    btnSincronizar.addEventListener('click', sincronizarVisitasPendientes);
}
</script>
</body>

</html>