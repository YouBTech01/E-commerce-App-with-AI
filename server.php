<?php
/**
 * Local development router for PHP's built-in server:
 *   php -S 0.0.0.0:8000 server.php
 * In production, use Apache (.htaccess provided) or Nginx (see docs).
 */

// Hardening headers for dev (parity with .htaccess in production).
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Permitted-Cross-Domain-Policies: none');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
// Serve existing static assets directly.
if ($path !== '/' && is_file($file) && !preg_match('#^/api#', $path)) {
    return false;
}
// Route /api/* to the front controller.
if (preg_match('#^/api#', $path)) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require __DIR__ . '/api/index.php';
    return true;
}
// SPA fallback for the student app.
if (is_file(__DIR__ . '/index.html')) {
    require __DIR__ . '/index.html';
    return true;
}
http_response_code(404);
echo 'Not found';
