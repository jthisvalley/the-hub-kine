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
        Schema::table('appointment_cancellation_reasons', function (Blueprint $table) {
            $table->foreign(['appointment_id'])->references(['id'])->on('appointments')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['cancellation_reason_id'])->references(['id'])->on('cancellation_reasons')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointment_cancellation_reasons', function (Blueprint $table) {
            $table->dropForeign('appointment_cancellation_reasons_appointment_id_foreign');
            $table->dropForeign('appointment_cancellation_reasons_cancellation_reason_id_foreign');
        });
    }
};
