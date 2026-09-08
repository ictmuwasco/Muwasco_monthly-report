<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 5 (DB performance): the login lookup matches `username = ? OR email = ?`.
 * `username` is unique-indexed but `email` was not indexed at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! collect(Schema::getIndexes('users'))->contains(
                fn (array $index) => $index['columns'] === ['email'],
            )) {
                $table->index('email', 'idx_users_email');
            }
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            if (! collect(Schema::getIndexes('audit_logs'))->contains(
                fn (array $index) => $index['columns'] === ['created_at'],
            )) {
                $table->index('created_at', 'idx_audit_created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_email');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_created_at');
        });
    }
};
