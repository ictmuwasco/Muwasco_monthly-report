<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security: track when a user last changed their password.
 *
 * NULL = never changed (seeded/admin-created account) → the SPA must force a
 * password change before granting access to the app. Stamped by
 * POST /api/v1/auth/change-password and by the admin reset-password action.
 *
 * Nullable ALTER on the live `users` table — safe, no default backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Already declared inline by the legacy-core-tables bootstrap.
        if (Schema::hasColumn('users', 'password_changed_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
