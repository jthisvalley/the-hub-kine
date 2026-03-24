<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');

            // Email settings
            $table->boolean('email_notifications')->default(true);
            $table->boolean('appointment_reminders')->default(true);
            $table->boolean('appointment_cancellations')->default(true);
            $table->boolean('appointment_confirmations')->default(true);
            $table->boolean('exercise_reminders')->default(true);
            $table->boolean('marketing_emails')->default(false);

            // SMS settings
            $table->boolean('sms_notifications')->default(false);
            $table->string('phone_number')->nullable();

            // WhatsApp settings
            $table->boolean('whatsapp_notifications')->default(false);
            $table->string('whatsapp_number')->nullable();

            // Push settings
            $table->boolean('push_notifications')->default(true);

            // Schedule settings
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->string('quiet_hours_start')->nullable();
            $table->string('quiet_hours_end')->nullable();
            $table->integer('max_daily_notifications')->default(20);
            $table->enum('notification_priority', ['high', 'medium', 'low'])->default('medium');

            $table->timestamps();

            // Indexes
            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_preferences');
    }
};
