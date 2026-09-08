<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M11 prerequisite: create the approval-workflow table.
     *
     * The live `maggie_monthlyreport` database (source of truth) has no approval table.
     * This additive migration creates `month_approvals` so the approval workflow (M11)
     * has a home. It does NOT modify any existing legacy table or data.
     *
     * Note: FK columns are *signed* `integer` to match legacy `months.id` / `users.id`
     * (int(11) signed). Using unsignedBigInteger would break the MySQL foreign keys.
     */
    public function up(): void
    {
        Schema::create('month_approvals', function (Blueprint $table) {
            $table->id();

            // Reporting period being approved (signed int to match months.id).
            $table->integer('month_id');
            // Which manager approver carries out this approval.
            $table->enum('manager_role', ['technical_manager', 'commercial_manager']);
            // Lifecycle of this approval request.
            $table->enum('status', ['pending', 'notified', 'approved', 'rejected'])
                  ->default('pending');

            $table->dateTime('notified_at')->nullable();
            $table->integer('notified_by')->nullable();

            $table->dateTime('approved_at')->nullable();
            // Single, unambiguous approver reference (resolves the old dual-column design).
            $table->integer('approved_by_user_id')->nullable();
            $table->text('rejection_reason')->nullable();

            // Signed email-link token + expiry.
            $table->string('approval_token', 64)->nullable();
            $table->dateTime('token_expires_at')->nullable();

            $table->timestamps();

            // One approval row per (month, manager_role).
            $table->unique(['month_id', 'manager_role'], 'unique_month_manager');
            $table->index('approval_token', 'idx_approval_token');

            $table->foreign('month_id', 'fk_month_approvals_month')
                  ->references('id')->on('months')->onDelete('cascade');
            $table->foreign('notified_by', 'fk_month_approvals_notified_by')
                  ->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_user_id', 'fk_month_approvals_approved_by_user')
                  ->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('month_approvals');
    }
};
