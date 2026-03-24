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
        Schema::create('kine_profiles', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36)->unique();
            $table->string('specialty')->nullable();
            $table->text('bio')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->boolean('is_emergency_contact_available')->default(true);
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('siret', 14)->nullable();
            $table->boolean('approved')->default(false);
            $table->json('notification_preferences')->nullable()->default('_utf8mb4\'{"email": true, "push": true, "sms": false}\'');
            $table->string('adeli_number', 50)->nullable();
            $table->text('specialties')->nullable();
            $table->integer('years_of_experience')->nullable();
            $table->json('working_days')->nullable()->default('_utf8mb4\'{"monday": true, "tuesday": true, "wednesday": true, "thursday": true, "friday": true, "saturday": false, "sunday": false}\'');
            $table->json('working_hours')->nullable()->default('_utf8mb4\'{"start": "08:00", "end": "18:00", "lunch_start": "12:00", "lunch_end": "14:00"}\'');
            $table->json('session_durations')->nullable()->default('_utf8mb4\'[30, 45, 60]\'');
            $table->integer('buffer_minutes')->nullable()->default(15);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kine_profiles');
    }
};
