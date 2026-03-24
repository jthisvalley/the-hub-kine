<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'category')) {
                $table->dropColumn('category');
            }

            $table->uuid('category_id')->nullable();
            $table->uuid('subcategory_id')->nullable();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->foreign('subcategory_id')->references('id')->on('subcategories')->onDelete('set null');

            $table->string('slug')->unique()->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_bestseller')->default(false);
            $table->json('specifications')->nullable();
            $table->json('benefits')->nullable();
            $table->json('how_to_use')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('kine_recommendations_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['subcategory_id']);

            $table->dropColumn([
                'category_id',
                'subcategory_id',
                'slug',
                'full_description',
                'original_price',
                'gallery_urls',
                'availability',
                'stock_quantity',
                'is_featured',
                'is_bestseller',
                'rental_price',
                'rental_period',
                'specifications',
                'benefits',
                'how_to_use',
                'is_active',
                'kine_recommendations_count'
            ]);

            $table->string('category')->nullable();
        });
    }
};
