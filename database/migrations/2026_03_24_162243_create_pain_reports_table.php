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
        Schema::create('pain_reports', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36);
            $table->char('kine_id', 36)->nullable()->index();
            $table->integer('pain_level');
            $table->string('location');
            $table->text('description')->nullable();
            $table->string('trigger')->nullable();
            $table->string('duration')->nullable();
            $table->json('medications')->nullable();
            $table->json('relieving_factors')->nullable();
            $table->json('worsening_factors')->nullable();
            $table->boolean('affects_sleep')->default(false);
            $table->boolean('affects_daily_activities')->default(false);
            $table->string('status')->default('reported');
            $table->text('kine_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->char('reviewed_by', 36)->nullable()->index('pain_reports_reviewed_by_foreign');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'created_at']);
            $table->index(['patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pain_reports');
    }
};
