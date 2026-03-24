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
        Schema::create('progress_report_requests', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36);
            $table->char('kine_id', 36);
            $table->text('reason');
            $table->enum('urgency', ['low', 'medium', 'high'])->default('medium');
            $table->date('preferred_date')->nullable();
            $table->enum('type', ['routine_checkup', 'pain_increase', 'plateau', 'new_symptoms', 'other']);
            $table->text('specific_concerns')->nullable();
            $table->enum('status', ['pending', 'reviewing', 'scheduled', 'completed', 'cancelled', 'in_progress'])->default('pending');
            $table->text('kine_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->char('progress_report_id', 36)->nullable()->index('progress_report_requests_progress_report_id_foreign');
            $table->timestamps();

            $table->index(['created_at', 'urgency']);
            $table->index(['kine_id', 'status']);
            $table->index(['patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('progress_report_requests');
    }
};
