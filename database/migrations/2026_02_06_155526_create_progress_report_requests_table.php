<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_report_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('kine_id')->constrained('users')->onDelete('cascade');

            // Request details
            $table->text('reason');
            $table->enum('urgency', ['low', 'medium', 'high'])->default('medium');
            $table->date('preferred_date')->nullable();
            $table->enum('type', ['routine_checkup', 'pain_increase', 'plateau', 'new_symptoms', 'other']);
            $table->text('specific_concerns')->nullable();

            // Status tracking
            $table->enum('status', ['pending', 'reviewing', 'scheduled', 'completed', 'cancelled'])->default('pending');
            $table->text('kine_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Relations
            $table->foreignUuid('progress_report_id')->nullable()->constrained('patient_progress_reports')->onDelete('set null');

            $table->timestamps();

            // Indexes
            $table->index(['patient_id', 'status']);
            $table->index(['kine_id', 'status']);
            $table->index(['created_at', 'urgency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_report_requests');
    }
};
