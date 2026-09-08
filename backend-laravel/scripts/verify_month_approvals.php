<?php
// Verify the newly created month_approvals table (columns, indexes, FKs).
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== month_approvals COLUMNS ===\n";
foreach (DB::select('SHOW COLUMNS FROM month_approvals') as $c) {
    printf("%-24s %-28s null=%s key=%s default=%s\n",
        $c->Field, $c->Type, $c->Null, $c->Key, $c->Default ?? 'NULL');
}

echo "\n=== INDEXES ===\n";
foreach (DB::select('SHOW INDEX FROM month_approvals') as $i) {
    if ($i->Key_name === 'PRIMARY') continue;
    printf("%-34s cols=%s unique=%s\n", $i->Key_name, $i->Column_name,
        $i->Non_unique == '0' ? 'YES' : 'no');
}

echo "\n=== FOREIGN KEYS ===\n";
foreach (DB::select("SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
      ON rc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA=kcu.CONSTRAINT_SCHEMA AND rc.TABLE_NAME=kcu.TABLE_NAME
    WHERE kcu.TABLE_SCHEMA=DATABASE() AND kcu.TABLE_NAME='month_approvals' AND kcu.REFERENCED_TABLE_NAME IS NOT NULL") as $f) {
    printf("%s -> %s.%s (delete=%s)\n", $f->COLUMN_NAME, $f->REFERENCED_TABLE_NAME, $f->REFERENCED_COLUMN_NAME, $f->DELETE_RULE);
}

echo "\n=== all tables now in DB ===\n";
foreach (DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME") as $r) {
    echo $r->TABLE_NAME . "\n";
}

echo "\n=== legacy users count (untouched) ===\n";
echo 'users=' . DB::table('users')->count() . ' monthly_data=' . DB::table('monthly_data')->count() . " months=" . DB::table('months')->count() . "\n";