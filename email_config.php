<?php
// email_config.php — SMTP settings loaded from .env (never hardcode credentials).
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
