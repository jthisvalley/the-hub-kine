<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('exercise_id')->constrained('exercises')->onDelete('cascade');
            $table->timestamp('completed_at')->useCurrent();
            $table->integer('pain_level')->nullable()->check('pain_level >= 1 AND pain_level <= 10');
            $table->text('notes')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'completed_at']);
            $table->index(['exercise_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
