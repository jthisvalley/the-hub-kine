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
        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('loyalty_points_patient_id_foreign');
            $table->integer('total_points')->nullable()->default(0);
            $table->integer('available_points')->nullable()->default(0);
            $table->integer('level')->nullable()->default(1)->index('idx_loyalty_points_level');
            $table->integer('streak_current')->nullable()->default(0)->index('idx_loyalty_points_streak');
            $table->integer('streak_longest')->nullable()->default(0);
            $table->date('last_activity_date')->nullable();
            $table->date('last_exercise_date')->nullable();
            $table->integer('exercises_completed_today')->nullable();
            $table->tinyInteger('daily_streak_bonus_active')->nullable()->default(0);
            $table->tinyInteger('weekly_streak_bonus_active')->nullable()->default(0);
            $table->timestamps();

            $table->unique(['patient_id'], 'patient_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_points');
    }
};
