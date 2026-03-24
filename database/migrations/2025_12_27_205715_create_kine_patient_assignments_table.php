<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kine_patient_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kine_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('patient_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['kine_id', 'patient_id']);
            $table->index(['kine_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kine_patient_assignments');
    }
};
