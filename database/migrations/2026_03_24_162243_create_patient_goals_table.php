<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('patient_goals', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('patient_goals_patient_id_foreign');
            $table->char('kine_id', 36)->nullable()->index('patient_goals_kine_id_foreign');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('metric_type', 50)->nullable();
            $table->decimal('target_value', 10)->nullable();
            $table->decimal('current_value', 10)->nullable();
            $table->string('unit', 20)->nullable();
            $table->date('deadline')->nullable()->index('idx_patient_goals_deadline');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->nullable()->default('pending')->index('idx_patient_goals_status');
            $table->integer('progress_percentage')->nullable()->default(0);
            $table->timestamps();

            $table->index(['patient_id', 'status'], 'idx_patient_goals_patient_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_goals');
    }
};
