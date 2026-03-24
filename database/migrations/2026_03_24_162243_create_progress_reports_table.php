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
        Schema::create('progress_reports', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('progress_reports_patient_id_foreign');
            $table->char('kine_id', 36)->nullable()->index('progress_reports_kine_id_foreign');
            $table->string('title');
            $table->date('report_date');
            $table->text('summary')->nullable();
            $table->decimal('pain_improvement', 5)->nullable();
            $table->decimal('mobility_improvement', 5)->nullable();
            $table->decimal('adherence_percentage', 5)->nullable();
            $table->text('kine_notes')->nullable();
            $table->text('recommendations')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('progress_reports');
    }
};
