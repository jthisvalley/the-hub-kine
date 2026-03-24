<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pathologies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('color')->default('#3b82f6');
            $table->string('icon')->default('🏥');
            $table->boolean('is_active')->default(true);
            $table->integer('order_index')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('patient_pathologies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('patient_profile_id');
            $table->uuid('pathology_id');
            $table->date('diagnosed_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('patient_profile_id')->references('id')->on('patient_profiles')->onDelete('cascade');
            $table->foreign('pathology_id')->references('id')->on('pathologies')->onDelete('cascade');

            $table->unique(['patient_profile_id', 'pathology_id'], 'patient_pathology_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_pathologies');
        Schema::dropIfExists('pathologies');
    }
};
