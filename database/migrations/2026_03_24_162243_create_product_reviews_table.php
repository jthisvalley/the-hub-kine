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
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('product_id', 36)->index('product_reviews_product_id_foreign');
            $table->char('patient_id', 36)->index('product_reviews_patient_id_foreign');
            $table->integer('rating')->nullable();
            $table->string('title')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('verified_purchase')->nullable()->default(false);
            $table->integer('helpful_count')->nullable()->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
