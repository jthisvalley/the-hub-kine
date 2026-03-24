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
        Schema::create('patient_achievements', function (Blueprint $table) {
            $table->char('patient_id', 36)->index('patient_achievements_patient_id_foreign');
            $table->char('achievement_id', 36)->index('patient_achievements_achievement_id_foreign');
            $table->boolean('unlocked')->nullable()->default(false);
            $table->timestamp('unlocked_at')->nullable();
            $table->integer('progress')->nullable()->default(0);
            $table->timestamps();

            $table->index(['patient_id', 'unlocked'], 'idx_patient_achievements_unlocked');
            $table->index(['achievement_id']);
            $table->index(['patient_id']);
            $table->unique(['patient_id', 'achievement_id'], 'patient_achievement_unique');
            $table->primary(['patient_id', 'achievement_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_achievements');
    }
};
