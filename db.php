<?php
// db.php — Backward-compatible DB connection. Credentials come from .env only.

require_once __DIR__ . '/app/Helpers/Env.php';
env_load(__DIR__ . '/.env');

$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', '3306');
$user = env('DB_USER', 'root');
$pass = env('DB_PASS', '');
$db   = env('DB_NAME', '');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("$host:$port", $user, $pass, $db);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('[DB] Connection failed: ' . $e->getMessage());
    die('Database connection failed. Please try again later.');
}

