<?php
/**
 * public/index.php — Front controller / application router.
 *
 * Today the web server (XAMPP htdocs) serves the project root, where thin
 * legacy entry points (index.php, months.php, ...) preserve existing URLs.
 * This front controller is the target entry point once the vhost/docroot
 * is switched to public/. It maps clean and legacy URLs to the controllers.
 *
 * Usage with PHP built-in server (dev):
 *   php -S localhost:8000 public/index.php
 */

declare(strict_types=1);

/* ── Route map: preserved legacy URLs → backend controllers ─────── */
$appRoot = dirname(__DIR__);

$routes = [
    '/'                     => $appRoot . '/backend/app/Controllers/DashboardController.php',
    '/index.php'            => $appRoot . '/backend/app/Controllers/DashboardController.php',
    '/login.php'            => $appRoot . '/backend/app/Controllers/AuthController.php',
    '/logout.php'           => $appRoot . '/backend/app/Controllers/LogoutController.php',
    '/add_data.php'         => $appRoot . '/backend/app/Controllers/DataEntryController.php',
    '/months.php'           => $appRoot . '/backend/app/Controllers/ReportingPeriodController.php',
    '/reports.php'          => $appRoot . '/backend/app/Controllers/ReportController.php',
    '/monthly_reports.php'  => $appRoot . '/backend/app/Controllers/MonthlyReportController.php',
    '/assignment.php'       => $appRoot . '/backend/app/Controllers/AssignmentController.php',
    '/user_management.php'  => $appRoot . '/backend/app/Controllers/UserController.php',
];

/* Normalize the request path: strip the sub-directory install prefix
   (e.g. /monthly_report) so the same map works under Apache htdocs
   rewrites and under a vhost with docroot = public/. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$subDir = '/' . basename($appRoot);
if (str_starts_with($path, $subDir . '/')) {
    $path = substr($path, strlen($subDir));
} elseif ($path === $subDir) {
    $path = '/';
}

/* When docroot = project root (XAMPP), real asset files are served
   directly by Apache via .htaccess. When docroot = public/ (vhost or
   built-in server), serve static files here. */
$publicFile = __DIR__ . $path;
if ($path !== '/' && is_file($publicFile)) {
    return false; // let the web server handle static files
}

if (isset($routes[$path])) {
    require $routes[$path];
    return;
}

http_response_code(404);
echo '<h1>404 — Page not found</h1>';