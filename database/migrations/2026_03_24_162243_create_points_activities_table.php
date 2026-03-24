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
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index();
            $table->char('exercise_session_id', 36)->nullable()->index('points_activities_exercise_session_id_foreign');
            $table->char('milestone_id', 36)->nullable()->index('points_activities_milestone_id_foreign');
            $table->char('achievement_id', 36)->nullable()->index('points_activities_achievement_id_foreign');
            $table->integer('points')->default(0);
            $table->integer('streak_bonus')->default(0);
            $table->integer('daily_bonus')->default(0);
            $table->string('activity_type')->index();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
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
