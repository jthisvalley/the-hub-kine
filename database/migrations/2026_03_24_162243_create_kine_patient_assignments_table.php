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
        Schema::create('kine_patient_assignments', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kine_id', 36);
            $table->char('patient_id', 36)->index('kine_patient_assignments_patient_id_foreign');
            $table->timestamps();

            $table->index(['kine_id', 'created_at']);
            $table->unique(['kine_id', 'patient_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kine_patient_assignments');
    }
};
