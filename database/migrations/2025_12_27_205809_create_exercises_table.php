<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained('programs')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('video_url')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('sets')->nullable();
            $table->integer('reps')->nullable();
            $table->integer('rest_seconds')->nullable();
            $table->integer('order_index')->default(0);
            $table->timestamps();

            $table->index(['program_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
