<?php
// M2 verification: prove the Laravel app connects to the existing
// maggie_monthlyreport database and can read real legacy tables.
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = DB::connection()->getPdo();

echo 'database      : ' . $conn->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo 'roles         : ' . $conn->query('SELECT COUNT(*) FROM roles')->fetchColumn() . PHP_EOL;
echo 'parameters    : ' . $conn->query('SELECT COUNT(*) FROM parameters')->fetchColumn() . PHP_EOL;
echo 'monthly_data  : ' . $conn->query('SELECT COUNT(*) FROM monthly_data')->fetchColumn() . PHP_EOL;
echo 'months        : ' . $conn->query('SELECT COUNT(*) FROM months')->fetchColumn() . PHP_EOL;

$rows = $conn->query('SELECT id, name, status FROM months ORDER BY id DESC LIMIT 3')
              ->fetchAll(PDO::FETCH_ASSOC);
echo 'recent months : ' . json_encode($rows) . PHP_EOL;