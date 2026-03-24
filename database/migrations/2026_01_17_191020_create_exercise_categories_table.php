<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('exercise_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order_index')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn('category'); 
            $table->uuid('category_id')->nullable()->after('program_id');

            $table->foreign('category_id')->references('id')->on('exercise_categories')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
            $table->string('category')->nullable()->after('program_id');
        });

        Schema::dropIfExists('exercise_categories');
    }
};
