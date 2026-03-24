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
        Schema::create('redeemed_rewards', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('redeemed_rewards_patient_id_foreign');
            $table->char('reward_id', 36)->index('redeemed_rewards_reward_id_foreign');
            $table->integer('points_spent');
            $table->enum('status', ['pending', 'processing', 'shipped', 'delivered', 'completed'])->nullable()->default('pending');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redeemed_rewards');
    }
};
