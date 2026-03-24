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
        Schema::create('daily_checkins', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('daily_checkins_patient_id_foreign');
            $table->date('checkin_date')->index('idx_daily_checkins_date');
            $table->integer('overall_pain_level')->nullable();
            $table->string('mood', 50)->nullable();
            $table->integer('energy_level')->nullable();
            $table->decimal('sleep_hours', 3, 1)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'checkin_date'], 'idx_daily_checkins_patient_date');
            $table->unique(['patient_id', 'checkin_date'], 'unique_patient_checkin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_checkins');
    }
};
