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
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->uuid('kine_id')->nullable(); // Which kine to notify
            $table->integer('pain_level'); // 1-10
            $table->string('location'); // shoulder, back, knee, etc.
            $table->text('description')->nullable(); // Description of the pain
            $table->string('trigger')->nullable(); // What triggered the pain
            $table->string('duration')->nullable(); // How long it lasted
            $table->json('medications')->nullable(); // Medications taken
            $table->json('relieving_factors')->nullable(); // What helps
            $table->json('worsening_factors')->nullable(); // What makes it worse
            $table->boolean('affects_sleep')->default(false);
            $table->boolean('affects_daily_activities')->default(false);
            $table->string('status')->default('reported'); // reported, reviewed, addressed
            $table->text('kine_notes')->nullable(); // Notes from kine after review
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('reviewed_by')->nullable(); // Kine who reviewed
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('patient_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('kine_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');

            // Indexes for faster queries
            $table->index(['patient_id', 'created_at']);
            $table->index(['patient_id', 'status']);
            $table->index('kine_id');
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
