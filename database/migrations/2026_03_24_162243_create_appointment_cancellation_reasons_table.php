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
        Schema::create('appointment_cancellation_reasons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('appointment_id', 36)->index('appointment_cancellation_reasons_appointment_id_foreign');
            $table->char('cancellation_reason_id', 36)->index('appointment_cancellation_reasons_cancellation_reason_id_foreign');
            $table->text('additional_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_cancellation_reasons');
    }
};
