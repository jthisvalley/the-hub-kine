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
        Schema::create('patient_pathologies', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_profile_id', 36);
            $table->char('pathology_id', 36)->index('patient_pathologies_pathology_id_foreign');
            $table->date('diagnosed_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['patient_profile_id', 'pathology_id'], 'patient_pathology_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_pathologies');
    }
};
