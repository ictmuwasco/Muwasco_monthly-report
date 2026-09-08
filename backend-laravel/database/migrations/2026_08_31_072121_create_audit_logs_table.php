<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M4: append-only audit trail. Not editable by ordinary users (no endpoints update it;
     * only the audit service writes here). actor_user_id is a nullable signed int to match
     * live users.id (int(11) signed) — using unsignedBigInteger would break the FK.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Optional actor (null = system/guest).
            $table->integer('actor_user_id')->nullable();
            $table->string('action');                       // e.g. 'user.created', 'report_approval.decided'
            $table->string('entity_type')->nullable();      // e.g. 'App\Models\ReportingPeriod'
            $table->integer('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id'], 'idx_audit_entity');
            $table->index('actor_user_id', 'idx_audit_actor');
            $table->index('action', 'idx_audit_action');

            $table->foreign('actor_user_id', 'fk_audit_logs_actor')
                  ->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
