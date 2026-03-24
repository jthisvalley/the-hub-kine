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
        Schema::create('availability_settings', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kine_id', 36)->index('availability_settings_kine_id_foreign');
            $table->json('working_days');
            $table->time('work_start')->default('08:00:00');
            $table->time('work_end')->default('18:00:00');
            $table->boolean('has_lunch_break')->default(true);
            $table->time('lunch_start')->nullable()->default('12:00:00');
            $table->time('lunch_end')->nullable()->default('14:00:00');
            $table->integer('default_duration')->default(30);
            $table->integer('buffer_time')->default(15);
            $table->integer('max_advance_booking')->default(30);
            $table->integer('min_advance_booking')->default(2);
            $table->boolean('email_reminders')->default(true);
            $table->boolean('sms_reminders')->default(true);
            $table->integer('reminder_time')->default(24);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availability_settings');
    }
};
