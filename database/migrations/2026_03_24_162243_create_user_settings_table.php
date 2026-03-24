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
        Schema::create('user_settings', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36)->unique('user_id');
            $table->boolean('email_notifications')->nullable()->default(true);
            $table->boolean('push_notifications')->nullable()->default(true);
            $table->boolean('sms_notifications')->nullable()->default(false);
            $table->boolean('share_with_therapist')->nullable()->default(true);
            $table->boolean('share_for_research')->nullable()->default(false);
            $table->boolean('show_in_directory')->nullable()->default(false);
            $table->integer('font_size')->nullable()->default(16);
            $table->boolean('high_contrast')->nullable()->default(false);
            $table->boolean('reduce_motion')->nullable()->default(false);
            $table->string('dark_mode', 20)->nullable()->default('system');
            $table->integer('data_retention_days')->nullable()->default(365);
            $table->boolean('auto_delete_old_data')->nullable()->default(false);
            $table->timestamps();

            $table->index(['user_id'], 'user_settings_user_id_foreign');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
