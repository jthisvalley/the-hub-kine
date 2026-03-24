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
        Schema::create('marketplace_packs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10);
            $table->decimal('original_price', 10)->nullable();
            $table->decimal('price_per_session', 10)->nullable();
            $table->json('features')->nullable();
            $table->string('validity_period', 100)->nullable();
            $table->integer('loyalty_points')->nullable()->default(0);
            $table->boolean('is_popular')->nullable()->default(false);
            $table->string('icon', 50)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_packs');
    }
};
