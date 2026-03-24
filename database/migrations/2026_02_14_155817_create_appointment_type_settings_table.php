<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_type_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kine_id')->constrained('users')->onDelete('cascade');
            $table->string('type');
            $table->integer('default_duration')->default(30); 
            $table->decimal('default_price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['kine_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_type_settings');
    }
};
