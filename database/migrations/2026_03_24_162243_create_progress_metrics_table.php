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
        Schema::create('progress_metrics', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('idx_progress_metrics_patient');
            $table->string('metric_type', 50);
            $table->decimal('value', 10);
            $table->string('unit', 20)->nullable();
            $table->timestamp('measured_at')->useCurrentOnUpdate()->useCurrent();
            $table->text('notes')->nullable();
            $table->string('source', 50)->nullable()->default('manual');
            $table->timestamp('created_at')->nullable();

            $table->index(['patient_id', 'measured_at'], 'idx_progress_metrics_patient_measured');
            $table->index(['metric_type', 'measured_at'], 'idx_progress_metrics_type_measured');
            $table->index(['patient_id'], 'progress_metrics_patient_id_foreign');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('progress_metrics');
    }
};
