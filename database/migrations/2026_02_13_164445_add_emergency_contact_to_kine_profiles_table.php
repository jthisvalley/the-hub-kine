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
        Schema::table('kine_profiles', function (Blueprint $table) {
            $table->string('emergency_phone')->nullable()->after('address');
            $table->boolean('is_emergency_contact_available')->default(true)->after('emergency_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kine_profiles', function (Blueprint $table) {
            $table->dropColumn(['emergency_phone', 'is_emergency_contact_available']);
        });
    }
};
