<?php

/**
 * descargar.php
 * -----------------------------------------------------------------
 * Punto único para servir los archivos guardados en uploads/visitas/.
 *
 * En vez de enlazar directo a "uploads/visitas/.../archivo.ext" (lo
 * cual permite que cualquiera con el link vea el archivo sin haber
 * iniciado sesión), todas las páginas deben enlazar aquí:
 *
 *   descargar.php?tipo=evidencia&id=123   -> fotografías (tabla evidencias)
 *   descargar.php?tipo=archivo&id=45      -> documentos   (tabla archivos)
 *
 * Este script:
 *   1) exige sesión iniciada
 *   2) verifica que el usuario tenga permiso sobre esa visita
 *      (ADMIN ve todo, INVESTIGADOR solo lo suyo)
 *   3) confirma que el archivo resuelto sigue dentro de uploads/
 *      (protección contra path traversal)
 *   4) entrega el archivo con los encabezados correctos
 *
 * Colocar en la RAÍZ del proyecto (mismo nivel que config.php e index.php).
 * -----------------------------------------------------------------
 */

session_start();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit('Acceso no autorizado.');
}

require_once __DIR__ . '/config.php';

$usuario_id = (int) $_SESSION['usuario_id'];
$rol = $_SESSION['rol'] ?? '';

$tipo = $_GET['tipo'] ?? '';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || !in_array($tipo, ['evidencia', 'archivo'], true)) {
    http_response_code(400);
    exit('Solicitud inválida.');
}

/* =====================================================
   BUSCAR EL ADJUNTO Y VERIFICAR PERMISOS
   (el JOIN con visitas es lo que nos permite validar que
   el investigador solo pueda abrir SUS propios archivos)
===================================================== */

if ($tipo === 'evidencia') {

    $condicion_acceso = $rol === 'ADMIN'
        ? 'e.id = :id'
        : 'e.id = :id AND v.investigador_id = :usuario_id';

    $stmt = $pdo->prepare(
        "SELECT e.nombre_archivo AS nombre, e.ruta_archivo AS ruta, NULL AS tipo_mime
         FROM evidencias e
         INNER JOIN visitas v ON e.visita_id = v.id
         WHERE $condicion_acceso"
    );

} else {

    $condicion_acceso = $rol === 'ADMIN'
        ? 'a.id = :id'
        : 'a.id = :id AND v.investigador_id = :usuario_id';

    $stmt = $pdo->prepare(
        "SELECT a.nombre_original AS nombre, a.ruta AS ruta, a.tipo_mime AS tipo_mime
         FROM archivos a
         INNER JOIN visitas v ON a.visita_id = v.id
         WHERE $condicion_acceso"
    );
}

$parametros = [':id' => $id];

if ($rol !== 'ADMIN') {
    $parametros[':usuario_id'] = $usuario_id;
}

$stmt->execute($parametros);
$adjunto = $stmt->fetch();

if (!$adjunto) {
    http_response_code(404);
    exit('El archivo no existe o no tiene permisos para verlo.');
}

/* =====================================================
   RESOLVER LA RUTA REAL Y CONFIRMAR QUE ESTÁ DENTRO
   DE uploads/ (evita path traversal aunque la ruta
   venga de la base de datos y no del usuario)
===================================================== */

$ruta_absoluta = __DIR__ . '/' . ltrim($adjunto['ruta'], '/\\');
$ruta_real = realpath($ruta_absoluta);
$directorio_uploads = realpath(__DIR__ . '/uploads');

if (
    $ruta_real === false
    || $directorio_uploads === false
    || strpos($ruta_real, $directorio_uploads) !== 0
    || !is_file($ruta_real)
) {
    http_response_code(404);
    exit('El archivo no existe.');
}

/* =====================================================
   ENTREGAR EL ARCHIVO
===================================================== */

$tipo_mime = $adjunto['tipo_mime'];

if (!$tipo_mime) {
    $tipo_mime = function_exists('mime_content_type')
        ? (mime_content_type($ruta_real) ?: 'application/octet-stream')
        : 'application/octet-stream';
}

// ?descargar=1 fuerza la descarga; sin ese parámetro se muestra
// inline (útil para las miniaturas de fotos y para "Ver").
$disposicion = (($_GET['descargar'] ?? '') === '1') ? 'attachment' : 'inline';
$nombre_descarga = basename($adjunto['nombre']);

header('Content-Type: ' . $tipo_mime);
header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre_descarga . '"');
header('Content-Length: ' . filesize($ruta_real));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($ruta_real);
exit;
