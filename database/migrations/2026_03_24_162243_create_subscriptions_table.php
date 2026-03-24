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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('subscriptions_patient_id_foreign');
            $table->enum('plan', ['free', 'basic', 'premium', 'pro'])->nullable()->default('free');
            $table->decimal('price', 10)->nullable();
            $table->enum('billing_period', ['monthly', 'yearly'])->nullable()->default('monthly');
            $table->enum('status', ['active', 'past_due', 'canceled', 'incomplete', 'trialing'])->nullable()->default('incomplete')->index('idx_subscriptions_status');
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable()->index('idx_subscriptions_period_end');
            $table->boolean('cancel_at_period_end')->nullable()->default(false);
            $table->date('trial_end')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status'], 'idx_subscriptions_patient_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
