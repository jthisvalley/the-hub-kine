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
        Schema::table('check_ins', function (Blueprint $table) {
            $table->foreign(['exercise_session_id'])->references(['id'])->on('exercise_sessions')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['patient_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropForeign('check_ins_exercise_session_id_foreign');
            $table->dropForeign('check_ins_patient_id_foreign');
        });
    }
};
