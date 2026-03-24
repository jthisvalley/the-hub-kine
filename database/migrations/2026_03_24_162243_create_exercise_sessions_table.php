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
        Schema::create('exercise_sessions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('exercise_sessions_patient_id_foreign');
            $table->char('program_assignment_id', 36)->nullable()->index('exercise_sessions_program_assignment_id_foreign');
            $table->char('exercise_id', 36)->index('exercise_sessions_exercise_id_foreign');
            $table->date('session_date')->index('idx_exercise_sessions_date');
            $table->time('session_time')->nullable();
            $table->integer('planned_repetitions')->nullable();
            $table->integer('actual_repetitions')->nullable();
            $table->integer('pain_level')->nullable();
            $table->enum('difficulty', ['easy', 'normal', 'hard', 'very_hard'])->nullable()->default('normal');
            $table->text('comments')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped', 'cancelled'])->nullable()->default('pending')->index('idx_exercise_sessions_status');
            $table->timestamps();

            $table->index(['patient_id'], 'idx_exercise_sessions_patient');
            $table->index(['patient_id', 'session_date'], 'idx_exercise_sessions_patient_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exercise_sessions');
    }
};
