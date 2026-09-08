<?php
// M4 verification: new tables + FK + legacy data intact.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== new table columns ===\n";
foreach (['audit_logs', 'approval_histories'] as $t) {
    echo "-- $t --\n";
    foreach (DB::select("SHOW COLUMNS FROM `$t`") as $c) {
        printf("   %-22s %-28s null=%s key=%s\n", $c->Field, $c->Type, $c->Null, $c->Key);
    }
}

echo "\n=== parameters FK present? ===\n";
$fk = DB::select("SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA=kcu.CONSTRAINT_SCHEMA AND rc.TABLE_NAME=kcu.TABLE_NAME
    WHERE kcu.TABLE_SCHEMA=DATABASE() AND kcu.TABLE_NAME='parameters' AND kcu.REFERENCED_TABLE_NAME IS NOT NULL");
foreach ($fk as $f) printf("   %s: %s -> %s.%s (delete=%s)\n", $f->CONSTRAINT_NAME, $f->COLUMN_NAME, $f->REFERENCED_TABLE_NAME, $f->REFERENCED_COLUMN_NAME, $f->DELETE_RULE);

echo "\n=== new-table FKs ===\n";
foreach (DB::select("SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, rc.DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA=kcu.CONSTRAINT_SCHEMA AND rc.TABLE_NAME=kcu.TABLE_NAME
    WHERE kcu.TABLE_SCHEMA=DATABASE() AND kcu.TABLE_NAME IN ('audit_logs','approval_histories') AND kcu.REFERENCED_TABLE_NAME IS NOT NULL") as $f) {
    printf("   %s.%s -> %s (delete=%s)\n", $f->TABLE_NAME, $f->COLUMN_NAME, $f->REFERENCED_TABLE_NAME, $f->DELETE_RULE);
}

echo "\n=== legacy data intact ===\n";
foreach (['users','roles','parameters','parameter_categories','user_parameter_assignments','user_section_assignments','months','monthly_data','month_approvals'] as $t) {
    printf("   %-28s %s\n", $t, DB::table($t)->count());
}

echo "\n=== audit_logs not writable by plain create? (we only claim append-only; verify write works + FK) ===\n";
DB::table('audit_logs')->insert(['action' => 'm4.verify', 'entity_type' => 'test', 'created_at' => now()]);
$id = DB::getPdo()->lastInsertId();
printf("   inserted audit_log id=%s, count=%s\n", $id, DB::table('audit_logs')->count());
// clean up the test row
DB::table('audit_logs')->where('id', $id)->delete();
printf("   after cleanup count=%s\n", DB::table('audit_logs')->count());