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
        Schema::create('kine_quotes', function (Blueprint $table) {
            $table->char('kine_id', 36);
            $table->char('patient_id', 36)->index('fk_kine_quotes_patient');
            $table->char('quote_id', 36)->index('kine_quotes_quote_id_foreign');
            $table->boolean('is_active')->default(true);
            $table->integer('order_index')->default(0);
            $table->timestamps();

            $table->unique(['kine_id', 'quote_id']);
            $table->primary(['kine_id', 'quote_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kine_quotes');
    }
};
