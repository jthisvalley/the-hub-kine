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
        Schema::create('orders', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('orders_patient_id_foreign');
            $table->string('order_number', 50)->index('idx_orders_number');
            $table->decimal('total_amount', 10);
            $table->decimal('subtotal', 10)->nullable();
            $table->decimal('shipping_cost', 10)->nullable()->default(0);
            $table->decimal('discount_amount', 10)->nullable()->default(0);
            $table->enum('status', ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'])->nullable()->default('pending');
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_status', 50)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->longText('notes')->nullable();
            $table->timestamp('created_at')->nullable()->index('idx_orders_created');
            $table->timestamp('updated_at')->nullable();

            $table->index(['patient_id', 'status'], 'idx_orders_patient_status');
            $table->unique(['order_number'], 'order_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
