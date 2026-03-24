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
        Schema::create('notifications', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36)->index('notifications_user_id_foreign');
            $table->string('type', 100);
            $table->string('title');
            $table->text('message');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->nullable()->default('medium');
            $table->boolean('is_read')->nullable()->default(false);
            $table->text('action_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index('idx_notifications_created');
            $table->timestamp('updated_at')->nullable();

            $table->index(['type', 'priority', 'created_at'], 'idx_notifications_type_priority');
            $table->index(['user_id', 'is_read'], 'idx_notifications_user_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
