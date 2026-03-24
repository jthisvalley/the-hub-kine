<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropForeign(['exercise_id']);

            $table->renameColumn('exercise_id', 'exercise_session_id');

            $table->foreign('exercise_session_id')->references('id')->on('exercise_sessions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropForeign(['exercise_session_id']);
            $table->renameColumn('exercise_session_id', 'exercise_id');
            $table->foreign('exercise_id')->references('id')->on('exercises')->onDelete('cascade');
        });
    }
};
