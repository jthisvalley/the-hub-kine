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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('cart_items_patient_id_foreign');
            $table->char('product_id', 36)->nullable()->index('cart_items_product_id_foreign');
            $table->char('service_pack_id', 36)->nullable()->index('cart_items_service_pack_id_foreign');
            $table->integer('quantity')->nullable()->default(1);
            $table->timestamp('added_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
