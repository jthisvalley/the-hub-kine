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
        Schema::create('achievements', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->enum('type', ['streak', 'milestone', 'exercise', 'consistency', 'social', 'challenge']);
            $table->enum('tier', ['bronze', 'silver', 'gold', 'platinum', 'diamond']);
            $table->integer('points');
            $table->string('category', 50)->nullable();
            $table->integer('target_value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
