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
        Schema::table('appointment_reminders', function (Blueprint $table) {
            $table->foreign(['appointment_id'])->references(['id'])->on('appointments')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointment_reminders', function (Blueprint $table) {
            $table->dropForeign('appointment_reminders_appointment_id_foreign');
        });
    }
};
