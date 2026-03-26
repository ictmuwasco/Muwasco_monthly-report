<?php
// email_config.php
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', 'karenjuduncan750@gmail.com');
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', 'utbipkrqhonwsquq'); // Use app-specific password
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'tls');
}
if (!defined('EMAIL_FROM')) {
    define('EMAIL_FROM', 'karenjuduncan750@gmail.com');
}
if (!defined('EMAIL_FROM_NAME')) {
    define('EMAIL_FROM_NAME', 'MUWASCO MONTHLY REPORT');
}
?>