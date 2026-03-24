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
        Schema::create('products', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kine_id', 36)->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('full_description')->nullable();
            $table->decimal('price', 10)->index('idx_products_price');
            $table->decimal('original_price', 10)->nullable();
            $table->integer('discount')->nullable();
            $table->decimal('rating', 3)->nullable()->default(0);
            $table->integer('review_count')->nullable()->default(0);
            $table->text('image_url');
            $table->json('gallery_urls')->nullable();
            $table->enum('availability', ['in-stock', 'limited', 'out-of-stock', 'discontinued'])->nullable()->default('in-stock')->index('idx_products_availability');
            $table->boolean('is_new')->nullable()->default(false);
            $table->boolean('is_featured')->nullable()->default(false);
            $table->decimal('rental_price', 10)->nullable();
            $table->string('rental_period', 50)->nullable();
            $table->json('specifications')->nullable();
            $table->timestamps();
            $table->char('category_id', 36)->nullable()->index('products_category_id_foreign');
            $table->char('subcategory_id', 36)->nullable()->index('products_subcategory_id_foreign');
            $table->string('slug')->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_bestseller')->default(false);
            $table->json('benefits')->nullable();
            $table->json('how_to_use')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('kine_recommendations_count')->default(0);

            $table->index(['is_featured', 'created_at'], 'idx_products_featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
