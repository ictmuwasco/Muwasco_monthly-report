<?php
/**
 * backend/config/mail.php — SMTP settings loaded from .env (never hardcode credentials).
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Helpers/Env.php';

if (!env_loaded()) {
    env_load(dirname(__DIR__, 2) . '/.env');
}

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', env('SMTP_HOST', 'smtp.gmail.com'));
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int) env('SMTP_PORT', '587'));
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', (string) env('SMTP_USERNAME', ''));
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', (string) env('SMTP_PASSWORD', ''));
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', (string) env('SMTP_ENCRYPTION', 'tls'));
}
if (!defined('EMAIL_FROM')) {
    define('EMAIL_FROM', (string) env('EMAIL_FROM', ''));
}
if (!defined('EMAIL_FROM_NAME')) {
    define('EMAIL_FROM_NAME', (string) env('EMAIL_FROM_NAME', 'MUWASCO MONTHLY REPORT'));
}