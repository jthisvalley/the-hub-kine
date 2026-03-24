<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kine_id')->constrained('users')->onDelete('cascade');

            // Working days
            $table->json('working_days');

            $table->time('work_start')->default('08:00:00');
            $table->time('work_end')->default('18:00:00');
            $table->boolean('has_lunch_break')->default(true);
            $table->time('lunch_start')->default('12:00:00')->nullable();
            $table->time('lunch_end')->default('14:00:00')->nullable();

            $table->integer('default_duration')->default(30);
            $table->integer('buffer_time')->default(15);
            $table->integer('max_advance_booking')->default(30); // days
            $table->integer('min_advance_booking')->default(2); // hours

            $table->boolean('email_reminders')->default(true);
            $table->boolean('sms_reminders')->default(true);
            $table->integer('reminder_time')->default(24); // hours before

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_settings');
    }
};
