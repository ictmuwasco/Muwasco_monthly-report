<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the legacy `months` table into a full reporting-period lifecycle.
 *
 * Additive / non-destructive:
 *  - The `status` enum is WIDENED. Existing values ('draft', 'submitted') remain
 *    valid and untouched; new lifecycle states are appended for M9–M11.
 *  - A nullable `submission_deadline` column is added (no backfill needed).
 *  - A `status` index is added for dashboard/report filtering.
 */
return new class extends Migration
{
    /**
     * Full lifecycle status set. The first two values are the legacy enum values,
     * preserved in their original order for backward compatibility.
     */
    public const STATUSES = [
        'draft', 'submitted',                      // legacy values (unchanged)
        'open', 'under_review', 'changes_requested', // added by M9/M11
        'approved', 'rejected', 'closed',           // added by M9/M11
    ];

    public function up(): void
    {
        // Skipped when the legacy-core-tables bootstrap already created
        // `months` with the full lifecycle enum + deadline + index
        // (covers clean-DB installs and SQLite test schema).
        if (Schema::hasColumn('months', 'submission_deadline')) {
            return;
        }

        Schema::table('months', function (Blueprint $table) {
            DB::statement(sprintf(
                "ALTER TABLE `months` MODIFY `status` ENUM('%s') NULL DEFAULT 'draft'",
                implode("','", self::STATUSES)
            ));

            $table->date('submission_deadline')->nullable()->after('end_date')
                ->comment('Last day data may be submitted/edited for this period');

            $table->index('status', 'idx_months_status');
        });
    }

    public function down(): void
    {
        Schema::table('months', function (Blueprint $table) {
            $table->dropIndex('idx_months_status');
            $table->dropColumn('submission_deadline');

            // Restore the legacy enum. Fails safely if any row now holds a
            // non-legacy status — that is intentional protection of new data.
            DB::statement("ALTER TABLE `months` MODIFY `status` ENUM('draft','submitted') NULL DEFAULT 'draft'");
        });
    }
};

