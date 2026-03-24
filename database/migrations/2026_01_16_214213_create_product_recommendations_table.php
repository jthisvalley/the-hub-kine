<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('kine_id');
            $table->uuid('patient_id');
            $table->uuid('product_id');
            $table->text('notes')->nullable();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->enum('status', ['pending', 'purchased', 'using', 'completed'])->default('pending');
            $table->date('assigned_date');
            $table->timestamp('purchased_date')->nullable();
            $table->date('usage_start_date')->nullable();
            $table->date('usage_end_date')->nullable();
            $table->text('adherence_notes')->nullable();
            $table->timestamps();

            $table->foreign('kine_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('patient_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->unique(['kine_id', 'patient_id', 'product_id'], 'unique_kine_patient_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recommendations');
    }
};
