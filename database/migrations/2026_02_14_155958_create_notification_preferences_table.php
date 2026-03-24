<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->string('device_type')->nullable()->after('device_name');
            $table->string('os')->nullable()->after('device_type');
            $table->string('browser')->nullable()->after('os');
            $table->string('location')->nullable()->after('ip_address');
            $table->timestamp('first_used_at')->nullable()->after('last_used_at');
            $table->boolean('is_trusted')->default(false)->after('is_active');
        });
    }

    public function down()
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropColumn([
                'device_name',
                'device_type',
                'os',
                'browser',
                'location',
                'first_used_at',
                'is_trusted',
            ]);
        });
    }
};
