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

$base = rtrim(dirname(__DIR__), '/'); // project root

/* Legacy URL → entry point map (URLs are preserved). */
$routes = [
    '/'            => $base . '/index.php',
    '/index.php'   => $base . '/index.php',
    '/login.php'   => $base . '/login.php',
    '/logout.php'  => $base . '/logout.php',
    '/add_data.php'      => $base . '/add_data.php',
    '/months.php'        => $base . '/months.php',
    '/reports.php'       => $base . '/reports.php',
    '/monthly_reports.php' => $base . '/monthly_reports.php',
    '/assignment.php'    => $base . '/assignment.php',
    '/user_management.php' => $base . '/user_management.php',
];

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/* Serve real files (assets, images) directly when docroot = public/. */
$publicFile = __DIR__ . $path;
if ($path !== '/' && is_file($publicFile)) {
    return false; // let the built-in server / web server handle static files
}

if (isset($routes[$path])) {
    require $routes[$path];
    return;
}

http_response_code(404);
echo '<h1>404 — Page not found</h1>';