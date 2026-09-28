<?php
/**
 * ExamLegacy — bootstrap & configuration.
 * Loads .env (no secrets hard-coded), defines paths and helpers.
 * Include this ONCE at the top of the API front controller.
 */
declare(strict_types=1);

error_reporting(E_ALL);

// ---- Load .env ------------------------------------------------------------
// Minimal, dependency-free .env parser. Real secrets live only in .env / env vars.
(function (): void {
    $envPath = dirname(__DIR__) . '/.env';
    if (!is_readable($envPath)) {
        return;
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        // strip surrounding quotes
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        if (getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
})();

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false) {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }
    if ($v === false || $v === null) {
        return $default;
    }
    return $v;
}

function env_bool(string $key, bool $default = false): bool
{
    $v = env($key);
    if ($v === null || $v === '') {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

// ---- mbstring polyfills ----------------------------------------------------
// Some minimal PHP images ship without ext-mbstring. These native fallbacks
// keep the codebase working (UTF-8 aware) until the extension is installed.
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return (string) substr($string, $start, $length);
        }
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? (int) strlen($string) : count($chars);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        return strtoupper($string);
    }
}

// ---- Constants ------------------------------------------------------------
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', env_bool('APP_DEBUG', false));
define('APP_URL', rtrim(env('APP_URL', ''), '/'));

define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'examlegacy'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

define('FIREBASE_PROJECT_ID', env('FIREBASE_PROJECT_ID', ''));
define('FIREBASE_SERVICE_ACCOUNT', env('FIREBASE_SERVICE_ACCOUNT', ''));

define('CASHFREE_APP_ID', env('CASHFREE_APP_ID', ''));
define('CASHFREE_SECRET_KEY', env('CASHFREE_SECRET_KEY', ''));
define('CASHFREE_ENV', env('CASHFREE_ENV', 'sandbox'));
define('CASHFREE_API_VERSION', env('CASHFREE_API_VERSION', '2023-08-01'));
define('CASHFREE_WEBHOOK_SECRET', env('CASHFREE_WEBHOOK_SECRET', ''));

define('GEMINI_API_KEY', env('GEMINI_API_KEY', ''));
define('GEMINI_MODEL', env('GEMINI_MODEL', 'gemini-2.5-flash'));
define('GEMINI_API_BASE', rtrim(env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta'), '/'));

define('SMTP_HOST', env('SMTP_HOST', ''));
define('SMTP_PORT', (int) env('SMTP_PORT', '587'));
define('SMTP_USER', env('SMTP_USER', ''));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('SMTP_FROM', env('SMTP_FROM', ''));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'ExamLegacy'));
define('SMTP_SECURE', env('SMTP_SECURE', 'tls'));

define('STORAGE_PATH', rtrim(env('STORAGE_PATH', dirname(__DIR__) . '/storage'), '/'));
define('PDFS_DIR', STORAGE_PATH . '/' . trim(env('PDFS_DIR', 'pdfs'), '/'));
define('UPLOADS_DIR', STORAGE_PATH . '/' . trim(env('UPLOADS_DIR', 'uploads'), '/'));
define('PUBLIC_MEDIA_DIR', STORAGE_PATH . '/' . trim(env('PUBLIC_MEDIA_DIR', 'public'), '/'));

define('VIEWER_TOKEN_SECRET', env('VIEWER_TOKEN_SECRET', 'dev-insecure-change-me'));
define('VIEWER_SESSION_TTL', (int) env('VIEWER_SESSION_TTL', '7200'));

// ---- Superadmin console (dedicated email+password; ZERO Google dependency) ----
define('ADMIN_EMAIL', env('ADMIN_EMAIL', ''));
define('ADMIN_PASSWORD', env('ADMIN_PASSWORD', ''));
define('ADMIN_TOKEN_TTL', (int) env('ADMIN_TOKEN_TTL', '2592000')); // 30 days (spec)
define('ADMIN_TOKEN_SECRET', env('ADMIN_TOKEN_SECRET', (string) env('VIEWER_TOKEN_SECRET', 'dev-insecure-change-me')));

// ---- Firestore real-time mirror (optional; server-side service account only) ----
define('FIRESTORE_PROJECT_ID', env('FIRESTORE_PROJECT_ID', (string) env('FIREBASE_PROJECT_ID', '')));
define('FIRESTORE_SERVICE_ACCOUNT', env('FIRESTORE_SERVICE_ACCOUNT', (string) env('FIREBASE_SERVICE_ACCOUNT', '')));
define('FIRESTORE_SYNC', env_bool('FIRESTORE_SYNC', false));

// Composer autoloader (PHPMailer, Firestore SDK) if dependencies are installed.
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

// Autoload PHP classes from api/lib (PSR-4-ish: ExamLegacy\ => api/lib/)
spl_autoload_register(function (string $class): void {
    $prefix = 'ExamLegacy\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/lib/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- Error handling -------------------------------------------------------
if (APP_DEBUG) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
set_exception_handler(function (Throwable $e): void {
    error_log('[ExamLegacy] Uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'ok' => false,
        'error' => ['code' => 'server_error', 'message' => APP_DEBUG ? $e->getMessage() : 'Internal server error'],
    ], JSON_UNESCAPED_UNICODE);
});

// ---- Helpers --------------------------------------------------------------
function money(int $paise): string
{
    return number_format($paise / 100, 2);
}

/** Generate a cryptographically secure random hex string. */
function random_hex(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

/** Generate a short, human-friendly, collision-resistant code (e.g. order codes). */
function public_code(string $prefix, int $entropy = 10): string
{
    return $prefix . strtoupper(bin2hex(random_bytes($entropy)));
}

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string) $_SERVER[$k])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function ip_to_binary(?string $ip): ?string
{
    if ($ip === null) {
        return null;
    }
    $bin = @inet_pton($ip);
    return $bin === false ? null : $bin;
}
