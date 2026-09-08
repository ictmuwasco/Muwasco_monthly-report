<?php
// Inspect the LIVE authoritative DB schema (columns, keys, FKs, views).
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdo = DB::connection()->getPdo();

echo "=== TABLES ===" . PHP_EOL;
foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    echo $t . PHP_EOL;
}

echo PHP_EOL . "=== VIEWS ===" . PHP_EOL;
foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    echo $t . PHP_EOL;
}

echo PHP_EOL . "=== COLUMNS (live) ===" . PHP_EOL;
$cols = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> '?noop?' ORDER BY TABLE_NAME, ORDINAL_POSITION");
foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $c) {
    printf("%-24s %-22s %-32s null=%-3s def=%-14s key=%-8s extra=%s\n",
        $c['TABLE_NAME'], $c['COLUMN_NAME'], $c['COLUMN_TYPE'], $c['IS_NULLABLE'],
        (string)$c['COLUMN_DEFAULT'], (string)$c['COLUMN_KEY'], $c['EXTRA']);
}

echo PHP_EOL . "=== FOREIGN KEYS (live) ===" . PHP_EOL;
$fks = $pdo->query("SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE kcu JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA=kcu.CONSTRAINT_SCHEMA AND rc.TABLE_NAME=kcu.TABLE_NAME WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME");
foreach ($fks->fetchAll(PDO::FETCH_ASSOC) as $f) {
    printf("%s: [%s] %s -> %s.%s (delete=%s)\n", $f['TABLE_NAME'], $f['CONSTRAINT_NAME'], $f['COLUMN_NAME'], $f['REFERENCED_TABLE_NAME'], $f['REFERENCED_COLUMN_NAME'], $f['DELETE_RULE']);
}

echo PHP_EOL . "=== UNIQUE/INDEX keys (live) ===" . PHP_EOL;
foreach ($pdo->query("SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME")->fetchAll(PDO::FETCH_ASSOC) as $i) {
    printf("%-24s %-28s cols=[%s] unique=%s\n", $i['TABLE_NAME'], $i['INDEX_NAME'], $i['cols'], $i['NON_UNIQUE'] == '0' ? 'YES' : 'no');
}

echo PHP_EOL . "=== ROW COUNTS (live) ===" . PHP_EOL;
foreach (['users','roles','parameters','parameter_categories','user_parameter_assignments','user_section_assignments','months','monthly_data'] as $t) {
    try { echo str_pad($t, 28) . (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . PHP_EOL; } catch (Throwable $e) { echo str_pad($t, 28) . 'ERR ' . $e->getMessage() . PHP_EOL; }
}