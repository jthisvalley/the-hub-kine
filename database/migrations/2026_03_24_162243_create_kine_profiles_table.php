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
            $table->json('notification_preferences')->nullable();
            $table->string('adeli_number', 50)->nullable();
            $table->text('specialties')->nullable();
            $table->integer('years_of_experience')->nullable();
            $table->json('working_days')->nullable();
            $table->json('working_hours')->nullable();
            $table->json('session_durations')->nullable();
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
