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
        Schema::create('programs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kine_id', 36);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('duration')->nullable();
            $table->string('difficulty')->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_alt')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            $table->index(['kine_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
