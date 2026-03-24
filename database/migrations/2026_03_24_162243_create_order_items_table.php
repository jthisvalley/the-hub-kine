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
        Schema::create('order_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('order_id', 36)->index('order_items_order_id_foreign');
            $table->char('product_id', 36)->nullable()->index('order_items_product_id_foreign');
            $table->char('service_pack_id', 36)->nullable()->index('order_items_service_pack_id_foreign');
            $table->integer('quantity');
            $table->decimal('unit_price', 10);
            $table->decimal('total_price', 10);
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
