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
        Schema::create('check_ins', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36);
            $table->char('exercise_session_id', 36);
            $table->timestamp('completed_at')->useCurrent();
            $table->integer('pain_level')->nullable();
            $table->text('notes')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['exercise_session_id', 'completed_at'], 'check_ins_exercise_id_completed_at_index');
            $table->index(['patient_id', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
