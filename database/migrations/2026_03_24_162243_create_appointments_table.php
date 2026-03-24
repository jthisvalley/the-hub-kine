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
        Schema::create('appointments', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36);
            $table->char('slot_id', 36);
            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'pending'])->default('pending');
            $table->string('type', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_online')->nullable()->default(false);
            $table->text('video_link')->nullable();
            $table->string('meeting_code')->nullable();
            $table->decimal('price', 10)->nullable();
            $table->string('color')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index(['slot_id', 'status']);
            $table->index(['patient_id', 'created_at'], 'idx_appointments_patient_created');
            $table->index(['status', 'created_at'], 'idx_appointments_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
