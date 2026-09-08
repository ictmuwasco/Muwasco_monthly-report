<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * Builds the legacy-compatible schema in the per-test in-memory SQLite DB
 * (phpunit.xml). The live MySQL database is NEVER touched by tests.
 */
trait UsesSqliteLegacySchema
{
    protected function setUpSqliteSchema(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('username')->unique();
                $table->string('email')->unique();
                $table->string('full_name');
                $table->string('password');
                $table->timestamp('password_changed_at')->nullable();
                $table->enum('role', ['admin', 'user'])->default('user');
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function ($table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function ($table) {
                $table->id();
                $table->integer('actor_user_id')->nullable();
                $table->string('action');
                $table->string('entity_type')->nullable();
                $table->integer('entity_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->text('description')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('parameter_categories')) {
            Schema::create('parameter_categories', function ($table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->integer('display_order')->default(0);
            });
        }

        if (! Schema::hasTable('parameters')) {
            Schema::create('parameters', function ($table) {
                $table->id();
                $table->unsignedInteger('category_id')->nullable();
                $table->string('code')->unique();
                $table->text('label');
                $table->text('description')->nullable();
                $table->enum('data_type', ['number', 'text', 'currency', 'percentage'])->default('text');
                $table->string('unit', 50)->nullable();
                $table->boolean('required')->default(false);
                $table->integer('display_order')->default(0);
            });
        }

        if (! Schema::hasTable('user_parameter_assignments')) {
            Schema::create('user_parameter_assignments', function ($table) {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('parameter_id');
                $table->timestamp('assigned_at')->nullable()->useCurrent();
                $table->unique(['user_id', 'parameter_id'], 'unique_user_parameter');
            });
        }

        if (! Schema::hasTable('user_section_assignments')) {
            Schema::create('user_section_assignments', function ($table) {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('category_id');
                $table->timestamp('assigned_at')->nullable()->useCurrent();
                $table->unique(['user_id', 'category_id'], 'unique_user_category');
            });
        }
        if (! Schema::hasTable('months')) {
            Schema::create('months', function ($table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('month_year', 20)->unique();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->date('submission_deadline')->nullable();
                // Widened lifecycle enum (M9). SQLite has no native enum;
                // validation is enforced by the FormRequest + transition map.
                $table->string('status', 30)->default('draft');
                $table->string('created_by', 100)->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('monthly_data')) {
            Schema::create('monthly_data', function ($table) {
                $table->id();
                $table->unsignedInteger('month_id');
                $table->unsignedInteger('parameter_id');
                $table->text('value')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->unique(['month_id', 'parameter_id'], 'unique_month_parameter');
            });
        }

        if (! Schema::hasTable('month_approvals')) {
            Schema::create('month_approvals', function ($table) {
                $table->id();
                $table->unsignedInteger('month_id');
                $table->string('manager_role', 50);
                $table->string('status', 30)->default('pending');
                $table->timestamp('notified_at')->nullable();
                $table->unsignedInteger('notified_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedInteger('approved_by_user_id')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->string('approval_token', 64)->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->timestamps();
                $table->unique(['month_id', 'manager_role'], 'unique_month_manager_role');
            });
        }

        if (! Schema::hasTable('approval_histories')) {
            Schema::create('approval_histories', function ($table) {
                $table->id();
                $table->unsignedInteger('approval_id');
                $table->string('action', 30);
                $table->string('prev_status', 30)->nullable();
                $table->string('new_status', 30)->nullable();
                $table->unsignedInteger('actor_user_id')->nullable();
                $table->text('comment')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }
    }
}
