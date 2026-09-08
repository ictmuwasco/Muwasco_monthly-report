<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy core tables bootstrap.
 *
 * Fixes "migration drift": previously, migrations assumed the legacy MySQL
 * database already contained these tables, so `migrate:fresh` on a CLEAN
 * database failed. This migration creates them from scratch, mirroring the
 * live production schema (verified via SHOW CREATE TABLE).
 *
 * Every create is guarded by hasTable(), so running against the live MySQL
 * database (where the tables exist) is a no-op — it only fills the ledger.
 *
 * Order matters: users/roles → parameter_categories → parameters (FK added
 * later by 2026_08_31_072123) → months → monthly_data → assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── users ────────────────────────────────────────────────────────
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('username', 100)->unique();
                $table->string('password');
                $table->timestamp('password_changed_at')->nullable();
                $table->string('full_name');
                $table->string('email')->nullable();
                $table->enum('role', ['admin', 'user'])->default('user');
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        // ── roles (catalog used by assignment UI) ────────────────────────
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->text('description')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        // ── parameter_categories ─────────────────────────────────────────
        if (! Schema::hasTable('parameter_categories')) {
            Schema::create('parameter_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->integer('display_order')->default(0);
            });
        }

        // ── parameters (FK to categories added by 2026_08_31_072123) ────
        if (! Schema::hasTable('parameters')) {
            Schema::create('parameters', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('category_id')->nullable();
                $table->string('code', 10)->unique();
                $table->text('label');
                $table->enum('data_type', ['number', 'text', 'currency', 'percentage'])->default('text');
                $table->string('unit', 50)->nullable();
                $table->boolean('required')->default(false);
            });
        }

        // ── months (reporting periods, full lifecycle enum from M9) ─────
        if (! Schema::hasTable('months')) {
            Schema::create('months', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('month_year', 20)->nullable()->unique();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->date('submission_deadline')->nullable()
                    ->comment('Last day data may be submitted/edited for this period');
                $table->enum('status', [
                    'draft', 'submitted', 'open', 'under_review',
                    'changes_requested', 'approved', 'rejected', 'closed',
                ])->default('draft')->index('idx_months_status');
                $table->string('created_by', 100)->default('System');
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        // ── monthly_data ─────────────────────────────────────────────────
        if (! Schema::hasTable('monthly_data')) {
            Schema::create('monthly_data', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('month_id')->nullable();
                $table->unsignedInteger('parameter_id')->nullable();
                $table->text('value')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->unique(['month_id', 'parameter_id'], 'unique_month_parameter');
                $table->foreign('month_id')->references('id')->on('months')->cascadeOnDelete();
                $table->foreign('parameter_id')->references('id')->on('parameters')->cascadeOnDelete();
            });
        }

        // ── user_parameter_assignments ───────────────────────────────────
        if (! Schema::hasTable('user_parameter_assignments')) {
            Schema::create('user_parameter_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('parameter_id');
                $table->timestamp('assigned_at')->useCurrent();
                $table->unique(['user_id', 'parameter_id'], 'unique_user_parameter');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('parameter_id')->references('id')->on('parameters')->cascadeOnDelete();
            });
        }

        // ── user_section_assignments ─────────────────────────────────────
        if (! Schema::hasTable('user_section_assignments')) {
            Schema::create('user_section_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('category_id');
                $table->timestamp('assigned_at')->useCurrent();
                $table->unique(['user_id', 'category_id'], 'unique_user_category');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('category_id')->references('id')->on('parameter_categories')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Drop in reverse dependency order.
        foreach ([
            'user_section_assignments',
            'user_parameter_assignments',
            'monthly_data',
            'months',
            'parameters',
            'parameter_categories',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

