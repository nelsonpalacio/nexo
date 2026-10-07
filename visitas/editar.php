<?php

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once '../config.php';
require_once '../csrf.php';

$usuario_id = (int) $_SESSION['usuario_id'];
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? '';
$inicial = strtoupper(substr(trim($nombre), 0, 1));
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';

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

function migrarAdjuntosVisita(
    PDO $pdo,
    int $visita_id,
    string $estado,
    string $institucion,
    string $codigo_visita
): void
{
    $directorios_categoria = crearEstructuraVisita($estado, $institucion, $codigo_visita);
    $directorios_anteriores = [];

    $consultas = [
        [
            'select' => 'SELECT id, ruta_archivo AS ruta, "OTROS" AS categoria FROM evidencias WHERE visita_id = :visita_id',
            'update' => 'UPDATE evidencias SET ruta_archivo = :ruta_nueva WHERE id = :id'
        ],
        [
            'select' => 'SELECT id, ruta, categoria FROM archivos WHERE visita_id = :visita_id',
            'update' => 'UPDATE archivos SET ruta = :ruta_nueva WHERE id = :id'
        ]
    ];

    foreach ($consultas as $consulta) {
        $stmt = $pdo->prepare($consulta['select']);
        $stmt->execute([':visita_id' => $visita_id]);

        foreach ($stmt->fetchAll() as $adjunto) {
            $categoria = isset($directorios_categoria[$adjunto['categoria']])
                ? $adjunto['categoria']
                : 'OTROS';
            $directorio_nuevo = $directorios_categoria[$categoria]['directorio'];
            $ruta_web_nueva = $directorios_categoria[$categoria]['ruta'];
            $nombre_archivo = basename($adjunto['ruta']);
            $ruta_nueva = $ruta_web_nueva . $nombre_archivo;
            $ruta_anterior = __DIR__ . '/../' . ltrim($adjunto['ruta'], '/\\');
            $ruta_destino = $directorio_nuevo . DIRECTORY_SEPARATOR . $nombre_archivo;

            if ($ruta_anterior !== $ruta_destino) {
                $directorio_anterior = dirname($ruta_anterior);
                $directorios_anteriores[$directorio_anterior] = true;

                if (!is_file($ruta_anterior)) {
                    continue;
                }

                if (!rename($ruta_anterior, $ruta_destino)) {
                    throw new RuntimeException('No fue posible reorganizar los archivos de la visita.');
                }
            }

            $stmt_actualizar = $pdo->prepare($consulta['update']);
            $stmt_actualizar->execute([
                ':ruta_nueva' => $ruta_nueva,
                ':id' => $adjunto['id']
            ]);
        }
    }

    foreach (array_keys($directorios_anteriores) as $directorio_anterior) {
        if (is_dir($directorio_anterior)) {
            $contenido = scandir($directorio_anterior);

            if ($contenido !== false && count($contenido) === 2) {
                rmdir($directorio_anterior);
            }
        }
    }
}

function guardarAdjuntosEdicion(
    PDO $pdo,
    int $visita_id,
    int $usuario_id,
    string $estado,
    string $institucion,
    string $codigo_visita
): void
{
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

    $campos = [
        'archivos_demograficos' => 'INFORMACION_DEMOGRAFICA',
        'archivos_sociales' => 'INFORMACION_SOCIAL',
        'archivos_economicos' => 'DATOS_ECONOMICOS',
        'archivos_otros' => 'OTROS',
        'archivos' => 'OTROS',
        'fotos' => null
    ];

    foreach ($campos as $campo => $categoria) {
        if (empty($_FILES[$campo]['name']) || !is_array($_FILES[$campo]['name'])) {
            continue;
        }

        foreach ($_FILES[$campo]['name'] as $indice => $nombre_original) {
            if ($_FILES[$campo]['error'][$indice] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($_FILES[$campo]['error'][$indice] !== UPLOAD_ERR_OK) {
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

if (!$id) {
    header('Location: visitas.php');
    exit;
}

$condicion_acceso = $rol === 'ADMIN'
    ? 'id = :id'
    : 'id = :id AND investigador_id = :usuario_id';

$stmt = $pdo->prepare(
    "SELECT id, codigo_visita, tipo_institucion, estado_venezuela, ciudad,
            institucion, dependencia, fecha_visita, funcionario,
            cargo_funcionario, contacto_funcionario, observaciones
     FROM visitas
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
    exit('La visita no existe o no tiene permisos para editarla.');
}

$stmt_instituciones = $pdo->prepare(
    'SELECT DISTINCT c.gobernacion, c.estado FROM capitales c
         WHERE c.estado_activo = 1
             AND c.gobernacion IS NOT NULL
             AND c.gobernacion <> ""
             AND NOT EXISTS (
                     SELECT 1 FROM visitas v
                     WHERE v.id <> :visita_id
                         AND LOWER(TRIM(v.institucion)) = LOWER(TRIM(c.gobernacion))
             )
         ORDER BY c.gobernacion'
);
$stmt_instituciones->execute([':visita_id' => $id]);
$instituciones_por_estado = [];
foreach ($stmt_instituciones->fetchAll() as $institucion_disponible) {
    $instituciones_por_estado[$institucion_disponible['gobernacion']] = $institucion_disponible['estado'];
}
$instituciones_disponibles = array_keys($instituciones_por_estado);
$institucion_es_personalizada = !in_array($visita['institucion'], $instituciones_disponibles, true);
$categorias_archivo = [
    'INFORMACION_DEMOGRAFICA' => 'Información demográfica',
    'INFORMACION_SOCIAL' => 'Información social',
    'DATOS_ECONOMICOS' => 'Datos económicos (unidades productivas)',
    'OTROS' => 'Otros'
];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $campos = [
        'codigo_visita',
        'tipo_institucion',
        'estado_venezuela',
        'ciudad',
        'institucion',
        'dependencia',
        'fecha_visita',
        'funcionario',
        'cargo_funcionario',
        'contacto_funcionario',
        'observaciones'
    ];

    $institucion_seleccionada = trim($_POST['institucion'] ?? '');
    $institucion_otra = trim($_POST['institucion_otra'] ?? '');
    $visita['institucion'] = $institucion_seleccionada === '__OTRA__'
        ? $institucion_otra
        : $institucion_seleccionada;

    foreach ($campos as $campo) {
        if ($campo === 'institucion') continue;
        $visita[$campo] = trim($_POST[$campo] ?? '');
    }

    if ($visita['codigo_visita'] === '') {
        $stmt_codigo_actual = $pdo->prepare('SELECT codigo_visita FROM visitas WHERE id = :id');
        $stmt_codigo_actual->execute([':id' => $id]);
        $visita['codigo_visita'] = (string) $stmt_codigo_actual->fetchColumn();
    }

    if ($visita['institucion'] === '') {
        $error = 'Debe ingresar el nombre de la institución.';
    } elseif ($visita['tipo_institucion'] === '') {
        $error = 'Debe seleccionar el tipo de institución.';
    } elseif ($visita['estado_venezuela'] === '') {
        $error = 'Debe ingresar el estado de Venezuela.';
    } elseif ($visita['ciudad'] === '') {
        $error = 'Debe ingresar la ciudad.';
    } elseif ($visita['fecha_visita'] === '') {
        $error = 'Debe indicar la fecha de la visita.';
    }

    if ($error === '' && $institucion_seleccionada !== '__OTRA__') {
        if (!isset($instituciones_por_estado[$visita['institucion']])) {
            $error = 'La institución seleccionada no está disponible.';
        } elseif ($instituciones_por_estado[$visita['institucion']] !== $visita['estado_venezuela']) {
            $error = 'El estado no corresponde a la institución seleccionada.';
        }
    }

    if ($error === '') {
        $stmt_institucion = $pdo->prepare(
            'SELECT COUNT(*) FROM visitas
             WHERE id <> :visita_id
               AND LOWER(TRIM(institucion)) = LOWER(TRIM(:institucion))'
        );
        $stmt_institucion->execute([
            ':visita_id' => $id,
            ':institucion' => $visita['institucion']
        ]);

        if ((int) $stmt_institucion->fetchColumn() > 0) {
            $error = 'La institución seleccionada ya tiene otra visita registrada.';
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $sql = "UPDATE visitas
                    SET codigo_visita = :codigo_visita,
                        tipo_institucion = :tipo_institucion,
                        estado_venezuela = :estado_venezuela,
                        ciudad = :ciudad,
                        institucion = :institucion,
                        dependencia = :dependencia,
                        fecha_visita = :fecha_visita,
                        funcionario = :funcionario,
                        cargo_funcionario = :cargo_funcionario,
                        contacto_funcionario = :contacto_funcionario,
                        observaciones = :observaciones
                    WHERE $condicion_acceso";

            $stmt = $pdo->prepare($sql);
            $parametros = [
                ':codigo_visita' => $visita['codigo_visita'],
                ':tipo_institucion' => $visita['tipo_institucion'],
                ':estado_venezuela' => $visita['estado_venezuela'],
                ':ciudad' => $visita['ciudad'],
                ':institucion' => $visita['institucion'],
                ':dependencia' => $visita['dependencia'],
                ':fecha_visita' => $visita['fecha_visita'],
                ':funcionario' => $visita['funcionario'],
                ':cargo_funcionario' => $visita['cargo_funcionario'],
                ':contacto_funcionario' => $visita['contacto_funcionario'],
                ':observaciones' => $visita['observaciones'],
                ':id' => $id
            ];

            if ($rol !== 'ADMIN') {
                $parametros[':usuario_id'] = $usuario_id;
            }

            $stmt->execute($parametros);
            migrarAdjuntosVisita(
                $pdo,
                $id,
                $visita['estado_venezuela'],
                $visita['institucion'],
                $visita['codigo_visita']
            );
            guardarAdjuntosEdicion(
                $pdo,
                $id,
                $usuario_id,
                $visita['estado_venezuela'],
                $visita['institucion'],
                $visita['codigo_visita']
            );
            $pdo->commit();
            header('Location: visitas.php?actualizado=1');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('NEXO - error al actualizar visita #' . $id . ': ' . $e->getMessage());

            $error = 'No fue posible actualizar la visita. Intente de nuevo o contacte al administrador.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar visita | NEXO</title>
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
                <div class="breadcrumb">NEXO / Visitas / Editar visita</div>
                <h1>Editar visita</h1>
                <p>Actualice la información registrada y los datos de la persona que entregó la información.</p>
            </div>
            <div class="fecha"><?= date('d/m/Y') ?></div>
        </header>

        <?php if ($error !== ''): ?>
            <div class="alerta alerta-error"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="panel formulario-visita">
            <form method="POST" action="editar.php?id=<?= (int) $id ?>" enctype="multipart/form-data">
                <?= csrf_campo() ?>
                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <h2>Identificación de la visita</h2>
                        <p>Información básica de la visita realizada.</p>
                    </div>
                    <div class="form-grid">
                        <div class="campo">
                            <label for="codigo_visita">Código de visita</label>
                            <input type="text" id="codigo_visita" name="codigo_visita" value="<?= e($visita['codigo_visita']) ?>" readonly>
                            <small>El código fue generado por el sistema y no se puede modificar.</small>
                        </div>
                        <div class="campo">
                            <label for="fecha_visita">Fecha de la visita <span>*</span></label>
                            <input type="date" id="fecha_visita" name="fecha_visita" value="<?= e($visita['fecha_visita']) ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <h2>Institución visitada</h2>
                        <p>Registre dónde se realizó el levantamiento de información.</p>
                    </div>
                    <div class="form-grid">
                        <div class="campo campo-completo">
                            <label for="institucion">Nombre de la institución <span>*</span></label>
                            <select id="institucion" name="institucion" required onchange="mostrarInstitucionPersonalizada(this)">
                                <option value="">Seleccione la institución...</option>
                                <?php foreach ($instituciones_disponibles as $gobernacion): ?>
                                    <option value="<?= e($gobernacion) ?>" data-estado="<?= e($instituciones_por_estado[$gobernacion]) ?>" <?= $visita['institucion'] === $gobernacion ? 'selected' : '' ?>><?= e($gobernacion) ?></option>
                                <?php endforeach; ?>
                                <option value="__OTRA__" <?= $institucion_es_personalizada ? 'selected' : '' ?>>Otra institución</option>
                            </select>
                            <input type="text" id="institucion_otra" name="institucion_otra" value="<?= $institucion_es_personalizada ? e($visita['institucion']) : '' ?>" placeholder="Escriba el nombre de la institución" style="<?= $institucion_es_personalizada ? 'display:block;' : 'display:none;' ?> margin-top:8px;">
                        </div>
                        <div class="campo">
                            <label for="tipo_institucion">Tipo de institución <span>*</span></label>
                            <select id="tipo_institucion" name="tipo_institucion" required>
                                <option value="">Seleccione...</option>
                                <option value="EMBAJADA" <?= $visita['tipo_institucion'] === 'EMBAJADA' ? 'selected' : '' ?>>Embajada</option>
                                <option value="CANCILLERIA" <?= $visita['tipo_institucion'] === 'CANCILLERIA' ? 'selected' : '' ?>>Cancillería</option>
                                <option value="GOBERNACION" <?= $visita['tipo_institucion'] === 'GOBERNACION' ? 'selected' : '' ?>>Gobernación</option>
                                <option value="OTRA" <?= $visita['tipo_institucion'] === 'OTRA' ? 'selected' : '' ?>>Otra</option>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="estado_venezuela">Estado de Venezuela <span>*</span></label>
                            <input type="text" id="estado_venezuela" name="estado_venezuela" value="<?= e($visita['estado_venezuela']) ?>" <?= !$institucion_es_personalizada ? 'readonly' : '' ?> required>
                        </div>
                        <div class="campo">
                            <label for="ciudad">Ciudad <span>*</span></label>
                            <input type="text" id="ciudad" name="ciudad" value="<?= e($visita['ciudad']) ?>" required>
                        </div>
                        <div class="campo">
                            <label for="dependencia">Dependencia / oficina</label>
                            <input type="text" id="dependencia" name="dependencia" value="<?= e($visita['dependencia']) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <h2>Persona que entregó la información</h2>
                        <p>Registre los datos de la persona que suministró la información durante la visita.</p>
                    </div>
                    <div class="form-grid">
                        <div class="campo">
                            <label for="funcionario">Contacto / nombre de la persona</label>
                            <input type="text" id="funcionario" name="funcionario" value="<?= e($visita['funcionario']) ?>">
                        </div>
                        <div class="campo">
                            <label for="cargo_funcionario">Cargo o función</label>
                            <input type="text" id="cargo_funcionario" name="cargo_funcionario" value="<?= e($visita['cargo_funcionario']) ?>">
                        </div>
                        <div class="campo">
                            <label for="contacto_funcionario">Número telefónico</label>
                            <input type="tel" id="contacto_funcionario" name="contacto_funcionario" value="<?= e($visita['contacto_funcionario']) ?>" autocomplete="tel">
                        </div>
                    </div>
                </div>

                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <h2>Observaciones</h2>
                        <p>Registre información relevante obtenida durante la visita.</p>
                    </div>
                    <div class="campo campo-completo">
                        <label for="observaciones">Observaciones</label>
                        <textarea id="observaciones" name="observaciones" rows="6"><?= e($visita['observaciones']) ?></textarea>
                    </div>
                </div>

                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <h2>Agregar documentos y evidencias fotográficas</h2>
                        <p>Los archivos seleccionados se agregarán a los adjuntos existentes. Este paso es opcional.</p>
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
                            <small>Puede agregar varios archivos por categoría. Máximo 25 MB por archivo.</small>
                        </div>
                        <div class="campo">
                            <label for="fotos">Nuevas evidencias fotográficas</label>
                            <div class="carga-archivos carga-fotos" data-carga-archivos="fotos" data-aceptar="image/*" data-captura="environment">
                            </div>
                            <small>En celulares podrá abrir la cámara o elegir fotos existentes.</small>
                        </div>
                    </div>

                    <?php if (!empty($adjuntos)): ?>
                        <div class="adjuntos-existentes">
                            <strong>Adjuntos existentes</strong>
                            <ul>
                                <?php foreach ($adjuntos as $adjunto): ?>
                                    <li>
                                        <span><?= e($adjunto['categoria'] ? 'Archivo · ' . ($categorias_archivo[$adjunto['categoria']] ?? 'Otros') : $adjunto['tipo']) ?></span>
                                        <a href="../descargar.php?tipo=<?= e($adjunto['origen']) ?>&id=<?= (int) $adjunto['id'] ?>" target="_blank" rel="noopener">
                                            <?= e($adjunto['nombre']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="responsable-formulario">
                    <div class="mini-avatar"><?= e($inicial) ?></div>
                    <div><strong>Responsable del levantamiento</strong><span><?= e($nombre) ?></span></div>
                </div>

                <div class="form-acciones">
                    <a href="visitas.php" class="btn-cancelar">Cancelar</a>
                    <button type="submit" class="btn-principal">Guardar cambios</button>
                </div>
            </form>
        </section>
    </main>
</div>
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
            if (!archivo.type || archivo.type.indexOf('image/') !== 0 || archivo.size < 400 * 1024) return Promise.resolve(archivo);
            return new Promise(function (resolver) {
                var imagen = new Image(); var lector = new FileReader();
                lector.onload = function () { imagen.src = lector.result; };
                lector.onerror = function () { resolver(archivo); };
                imagen.onload = function () {
                    var escala = Math.min(1, 2200 / Math.max(imagen.width, imagen.height));
                    var lienzo = document.createElement('canvas');
                    lienzo.width = Math.round(imagen.width * escala); lienzo.height = Math.round(imagen.height * escala);
                    lienzo.getContext('2d').drawImage(imagen, 0, 0, lienzo.width, lienzo.height);
                    lienzo.toBlob(function (blob) {
                        var esPng = archivo.type === 'image/png';
                        var tipoSalida = esPng ? 'image/png' : 'image/jpeg';
                        var extensionSalida = esPng ? '.png' : '.jpg';
                        resolver(blob && blob.size < archivo.size ? new File([blob], archivo.name.replace(/\.[^.]+$/, extensionSalida), { type: tipoSalida }) : archivo);
                    }, archivo.type === 'image/png' ? 'image/png' : 'image/jpeg', 0.78);
                };
                imagen.onerror = function () { resolver(archivo); }; lector.readAsDataURL(archivo);
            });
        }

        formulario.addEventListener('submit', async function (evento) {
            if (formulario.dataset.optimizando === 'true') return;
            evento.preventDefault(); formulario.dataset.optimizando = 'true';
            var boton = formulario.querySelector('button[type="submit"]'); var textoOriginal = boton ? boton.textContent : '';
            if (boton) { boton.disabled = true; boton.textContent = 'Preparando archivos...'; }
            var entradas = Array.from(formulario.querySelectorAll('input[type="file"][name="fotos[]"]'));
            await Promise.all(entradas.map(async function (entrada) {
                if (!entrada.files.length) return;
                var optimizada = await comprimirImagen(entrada.files[0]);
                if (optimizada !== entrada.files[0] && typeof DataTransfer !== 'undefined') {
                    var transferencia = new DataTransfer(); transferencia.items.add(optimizada); entrada.files = transferencia.files;
                }
            }));
            if (boton) boton.textContent = textoOriginal;
            formulario.submit();
        });
    }());
</script>
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
        var estado = document.getElementById('estado_venezuela');
        var esOtra = selector.value === '__OTRA__';
        var opcion = selector.options[selector.selectedIndex];
        var estadoInstitucion = opcion ? opcion.dataset.estado : '';
        campo.style.display = esOtra ? 'block' : 'none';
        campo.required = esOtra;
        estado.readOnly = !esOtra && estadoInstitucion !== '';
        if (!esOtra && estadoInstitucion !== '') estado.value = estadoInstitucion;
        if (!esOtra) campo.value = '';
    }
</script>
</body>
</html>
