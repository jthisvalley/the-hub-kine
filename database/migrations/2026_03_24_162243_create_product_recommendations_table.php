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
        Schema::create('product_recommendations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kine_id', 36);
            $table->char('patient_id', 36)->index('product_recommendations_patient_id_foreign');
            $table->char('product_id', 36)->index('product_recommendations_product_id_foreign');
            $table->text('notes')->nullable();
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->enum('status', ['pending', 'purchased', 'using', 'completed'])->default('pending');
            $table->date('assigned_date');
            $table->timestamp('purchased_date')->nullable();
            $table->date('usage_start_date')->nullable();
            $table->date('usage_end_date')->nullable();
            $table->text('adherence_notes')->nullable();
            $table->timestamps();

            $table->unique(['kine_id', 'patient_id', 'product_id'], 'unique_kine_patient_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_recommendations');
    }
};
