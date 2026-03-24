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
        Schema::create('milestones', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('milestones_patient_id_foreign');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 50)->nullable();
            $table->boolean('achieved')->nullable()->default(false);
            $table->date('achieved_date')->nullable();
            $table->integer('target_value')->nullable();
            $table->integer('current_value')->nullable();
            $table->string('icon', 50)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
