<?php
/**
 * bootstrap/init.php — Single application entry include.
 *
 * Every page should start with:
 *   require_once __DIR__ . '/../bootstrap/init.php';
 *
 * Provides:
 *  - .env loading
 *  - secure session configuration + idle timeout
 *  - central error handling (safe user output, structured logging)
 *  - global DB connection ($conn) via env credentials
 *  - CSRF middleware availability
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Helpers/Env.php';
require_once __DIR__ . '/../app/Helpers/Logger.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';

env_load(dirname(__DIR__) . '/.env');

/* ── Error handling ─────────────────────────────────────────────── */
$appDebug = env('APP_DEBUG', 'false') === 'true';
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) use ($appDebug) {
    Logger::error('Unhandled exception', [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? '?',
        'user_id' => $_SESSION['user_id'] ?? null,
    ]);
    http_response_code(500);
    if ($appDebug) {
        echo '<pre>' . htmlspecialchars((string) $e) . '</pre>';
    } else {
        echo '<!DOCTYPE html><html><head><title>Error</title></head>'
           . '<body style="font-family:sans-serif;text-align:center;padding:3rem;">'
           . '<h1>Something went wrong</h1>'
           . '<p>Please try again or contact the system administrator if the problem persists.</p>'
           . '</body></html>';
    }
});

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) return false;
    Logger::warning('PHP error', compact('message', 'file', 'line'));
    return true;
});

/* ── Security headers (on every response) ───────────────────────── */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (($_ENV['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === 'on') {
    header('Strict-Transport-Security: max-age=31536000');
}

/* ── Secure session ─────────────────────────────────────────────── */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', env('SESSION_IDLE_TIMEOUT', '1800'));
    session_start();
}

// Idle timeout enforcement
$idleTimeout = (int) env('SESSION_IDLE_TIMEOUT', '1800');
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// Periodic ID rotation (every 30 min of activity) — mitigates fixation & hijacking
if (!isset($_SESSION['regenerated_at']) || time() - $_SESSION['regenerated_at'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['regenerated_at'] = time();
}

/* ── Database connection (credentials from .env only) ──────────── */
$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', '3306');
$user = env('DB_USER', 'root');
$pass = env('DB_PASS', '');
$db   = env('DB_NAME', '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // throw on SQL errors → handled centrally
$conn = new mysqli("$host:$port", $user, $pass, $db);
$conn->set_charset('utf8mb4');
