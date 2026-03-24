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
        Schema::create('patient_analytics', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('patient_analytics_patient_id_foreign');
            $table->string('period', 50)->nullable();
            $table->integer('total_exercises')->nullable()->default(0);
            $table->integer('completed_exercises')->nullable()->default(0);
            $table->decimal('adherence_rate', 5)->nullable()->default(0);
            $table->decimal('average_pain_level', 3, 1)->nullable();
            $table->decimal('average_mobility_level', 3, 1)->nullable();
            $table->integer('streak_current')->nullable()->default(0);
            $table->integer('total_points')->nullable()->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_analytics');
    }
};
