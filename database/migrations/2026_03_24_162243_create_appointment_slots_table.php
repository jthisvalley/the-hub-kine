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
        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kine_id', 36);
            $table->timestamp('start_time')->nullable()->index('idx_appointment_slots_start_time');
            $table->timestamp('end_time')->nullable()->index('idx_appointment_slots_end_time');
            $table->boolean('is_available');
            $table->timestamps();
            $table->date('slot_date')->nullable()->storedAs('cast(`start_time` as date)')->index('idx_appointment_slots_date');

            $table->index(['is_available', 'start_time']);
            $table->index(['kine_id', 'start_time']);
            $table->index(['is_available', 'start_time'], 'idx_appointment_slots_available_start');
            $table->index(['slot_date', 'is_available'], 'idx_appointment_slots_date_available');
            $table->index(['kine_id', 'slot_date'], 'idx_appointment_slots_kine_date');
            $table->index(['kine_id', 'start_time'], 'idx_appointment_slots_kine_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_slots');
    }
};
