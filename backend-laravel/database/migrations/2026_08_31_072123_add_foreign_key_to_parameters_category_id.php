<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M4: add a real FK constraint on parameters.category_id -> parameter_categories.id.
     * Verified safe: all 162 parameters have a valid (non-null, non-orphan) category_id.
     * ON DELETE RESTRICT (no cascade) to prevent accidental bulk deletion of categories.
     */
    public function up(): void
    {
        Schema::table('parameters', function (Blueprint $table) {
            $table->foreign('category_id', 'fk_parameters_category')
                  ->references('id')->on('parameter_categories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parameters', function (Blueprint $table) {
            $table->dropForeign('fk_parameters_category');
        });
    }
};
