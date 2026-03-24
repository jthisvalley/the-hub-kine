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
        Schema::create('points_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('patient_id');
            $table->uuid('exercise_session_id')->nullable();
            $table->uuid('milestone_id')->nullable();
            $table->uuid('achievement_id')->nullable();

            $table->integer('points')->default(0);
            $table->integer('streak_bonus')->default(0);
            $table->integer('daily_bonus')->default(0);

            $table->string('activity_type'); // achievement, exercise, milestone, etc.
            $table->text('description')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // Foreign Keys
            $table->foreign('patient_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('exercise_session_id')
                ->references('id')
                ->on('exercise_sessions')
                ->onDelete('set null');

            $table->foreign('milestone_id')
                ->references('id')
                ->on('milestones')
                ->onDelete('set null');

            $table->foreign('achievement_id')
                ->references('id')
                ->on('achievements')
                ->onDelete('set null');

            // Indexes for performance
            $table->index('patient_id');
            $table->index('activity_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('points_activities');
    }
};
