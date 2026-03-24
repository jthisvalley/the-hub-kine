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
        Schema::create('appointment_type_settings', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kine_id', 36);
            $table->string('type');
            $table->integer('default_duration')->default(30);
            $table->decimal('default_price', 10)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['kine_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_type_settings');
    }
};
