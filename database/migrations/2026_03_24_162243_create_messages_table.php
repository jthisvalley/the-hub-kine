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
        Schema::create('messages', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('sender_id', 36)->index('messages_sender_id_foreign');
            $table->char('receiver_id', 36)->index('messages_receiver_id_foreign');
            $table->text('content');
            $table->boolean('is_read')->nullable()->default(false);
            $table->timestamp('read_at')->nullable();
            $table->text('attachment_url')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['sender_id', 'receiver_id', 'created_at'], 'idx_messages_conversation');
            $table->index(['receiver_id', 'is_read', 'created_at'], 'idx_messages_receiver_read');
            $table->index(['sender_id', 'created_at'], 'idx_messages_sender_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
