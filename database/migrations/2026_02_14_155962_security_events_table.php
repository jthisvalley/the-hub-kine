<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('user_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('user_devices', 'is_trusted')) {
                $table->boolean('is_trusted')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('user_devices', 'first_used_at')) {
                $table->timestamp('first_used_at')->nullable()->after('last_used_at');
            }
            if (!Schema::hasColumn('user_devices', 'device_name')) {
                $table->string('device_name')->nullable()->after('device_fingerprint');
            }
            if (!Schema::hasColumn('user_devices', 'device_type')) {
                $table->string('device_type')->nullable()->after('device_name');
            }
            if (!Schema::hasColumn('user_devices', 'os')) {
                $table->string('os')->nullable()->after('device_type');
            }
            if (!Schema::hasColumn('user_devices', 'browser')) {
                $table->string('browser')->nullable()->after('os');
            }
            if (!Schema::hasColumn('user_devices', 'location')) {
                $table->string('location')->nullable()->after('ip_address');
            }
        });
    }

    public function down()
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropColumn([
                'is_trusted',
                'first_used_at',
                'device_name',
                'device_type',
                'os',
                'browser',
                'location'
            ]);
        });
    }
};
