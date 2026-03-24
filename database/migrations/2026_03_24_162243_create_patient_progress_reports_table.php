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
        Schema::create('patient_progress_reports', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36);
            $table->char('kine_id', 36);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->date('report_date');
            $table->enum('report_type', ['monthly', 'quarterly', 'on_demand', 'post_treatment']);
            $table->enum('status', ['draft', 'published', 'archived', 'in_progress', 'pending'])->default('draft');
            $table->decimal('pain_level_start', 4)->nullable();
            $table->decimal('pain_level_current', 4)->nullable();
            $table->decimal('pain_improvement', 5)->nullable();
            $table->decimal('mobility_score_start', 4)->nullable();
            $table->decimal('mobility_score_current', 4)->nullable();
            $table->decimal('mobility_improvement', 5)->nullable();
            $table->decimal('adherence_rate', 5)->nullable();
            $table->decimal('strength_improvement', 5)->nullable();
            $table->decimal('flexibility_improvement', 5)->nullable();
            $table->integer('total_sessions')->default(0);
            $table->integer('completed_sessions')->default(0);
            $table->integer('missed_sessions')->default(0);
            $table->integer('average_session_duration')->default(0);
            $table->text('kine_observations')->nullable();
            $table->text('kine_recommendations')->nullable();
            $table->text('next_steps')->nullable();
            $table->text('patient_comments')->nullable();
            $table->integer('patient_satisfaction')->nullable()->comment('1-5 scale');
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kine_id', 'report_date']);
            $table->index(['patient_id', 'report_date']);
            $table->index(['patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_progress_reports');
    }
};
