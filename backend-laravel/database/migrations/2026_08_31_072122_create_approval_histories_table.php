<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M4: audit trail of approval-workflow transitions (M11). Each row records one
     * transition of a month_approval. approval_id is unsignedBigInteger to match
     * month_approvals.id (bigint unsigned). actor_user_id is signed int to match users.id.
     */
    public function up(): void
    {
        Schema::create('approval_histories', function (Blueprint $table) {
            $table->id();

            // The approval being transitioned (FK to month_approvals.id).
            $table->unsignedBigInteger('approval_id');
            // What happened.
            $table->enum('action', ['notified', 'approved', 'rejected']);

            $table->string('prev_status')->nullable();
            $table->string('new_status')->nullable();

            // Who performed the action (manager). Nullable, matches users.id (int signed).
            $table->integer('actor_user_id')->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('approval_id', 'idx_approval_history_approval');
            $table->index('action', 'idx_approval_history_action');

            $table->foreign('approval_id', 'fk_approval_histories_approval')
                  ->references('id')->on('month_approvals')->onDelete('cascade');
            $table->foreign('actor_user_id', 'fk_approval_histories_actor')
                  ->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_histories');
    }
};
