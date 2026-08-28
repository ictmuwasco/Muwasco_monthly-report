<?php
/**
 * backend/config/database.php — Central DB connection factory.
 * Credentials come from .env only (never hardcoded).
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Helpers/Env.php';

if (!env_loaded()) {
    env_load(dirname(__DIR__, 2) . '/.env');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Return a shared mysqli connection (created once per request).
 */
function db_connection(): mysqli {
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    $host = env('DB_HOST', 'localhost');
    $port = env('DB_PORT', '3306');
    $user = env('DB_USER', 'root');
    $pass = env('DB_PASS', '');
    $db   = env('DB_NAME', '');

    try {
        $conn = new mysqli("$host:$port", $user, $pass, $db);
        $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
        error_log('[DB] Connection failed: ' . $e->getMessage());
        // Exception handler in backend/bootstrap/app.php renders the safe error page.
        throw $e;
    }

    return $conn;
}

/* Expose the legacy global $conn used throughout the application. */
$GLOBALS['conn'] = db_connection();
$conn = $GLOBALS['conn'];