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
        Schema::create('exercises', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('program_id', 36);
            $table->char('category_id', 36)->nullable()->index('exercises_category_id_foreign');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('video_url')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('sets')->nullable();
            $table->integer('reps')->nullable();
            $table->integer('rest_seconds')->nullable();
            $table->integer('order_index')->default(0);
            $table->enum('difficulty', ['beginner', 'intermediate', 'advanced'])->nullable()->default('beginner');
            $table->json('muscle_groups')->nullable();
            $table->json('instructions')->nullable();
            $table->timestamps();

            $table->index(['program_id', 'order_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
