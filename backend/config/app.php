<?php
/**
 * backend/config/app.php — Application-level configuration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Helpers/Env.php';

if (!env_loaded()) {
    env_load(dirname(__DIR__, 2) . '/.env');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'MUWASCO Monthly Performance Reporting System');
}
if (!defined('APP_ENV')) {
    define('APP_ENV', env('APP_ENV', 'production'));
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', env('APP_DEBUG', 'false') === 'true');
}

/**
 * Base URL path of the application (sub-directory install under htdocs).
 */
if (!function_exists('app_base')) {
    function app_base(): string {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $base === '' ? '' : $base;
    }
}