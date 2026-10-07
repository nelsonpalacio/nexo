<?php

/**
 * csrf.php
 * -----------------------------------------------------------------
 * Protección CSRF (Cross-Site Request Forgery) para los formularios
 * que modifican datos (crear/editar visitas, crear/editar/activar
 * usuarios, etc.)
 *
 * Sin esto, una página externa podría enviar un formulario oculto
 * hacia NEXO aprovechando la sesión ya iniciada de un usuario, y
 * el servidor no tendría forma de distinguir esa petición de una
 * que el usuario mandó a propósito.
 *
 * Uso en cada archivo que procese un formulario POST:
 *
 *   require_once __DIR__ . '/../csrf.php';   // (ajustar la ruta)
 *
 *   // Dentro del <form ...> ... </form>, en cualquier parte:
 *   <?= csrf_campo() ?>
 *
 *   // Al inicio del bloque que procesa el POST, antes de leer
 *   // cualquier otro dato de $_POST:
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 *       csrf_validar();
 *       ... resto del procesamiento ...
 *   }
 *
 * Requiere que session_start() ya se haya llamado antes.
 * -----------------------------------------------------------------
 */

/**
 * Devuelve el token CSRF de la sesión actual, generándolo la
 * primera vez que se necesita.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Devuelve el <input type="hidden"> listo para insertar dentro
 * de cualquier <form method="POST">.
 */
function csrf_campo(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');

    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Valida el token recibido en el POST contra el de la sesión.
 * Si no coincide (o falta), corta la ejecución con un 403.
 *
 * Llamar SIEMPRE al inicio del bloque que procesa un POST,
 * antes de leer o guardar cualquier otro campo del formulario.
 */
function csrf_validar(): void
{
    $token_formulario = $_POST['csrf_token'] ?? '';
    $token_sesion = $_SESSION['csrf_token'] ?? '';

    if ($token_sesion === '' || !hash_equals($token_sesion, $token_formulario)) {
        http_response_code(403);
        exit('El formulario expiró o no es válido. Recargue la página e inténtelo de nuevo.');
    }
}
