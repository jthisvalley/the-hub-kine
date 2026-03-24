<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cancellation_reasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reason');
            $table->string('type')->default('general'); 
            $table->boolean('is_active')->default(true);
            $table->integer('order_index')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('appointment_cancellation_reasons', function (Blueprint $table) {
            $table->id();
            $table->uuid('appointment_id');
            $table->uuid('cancellation_reason_id');
            $table->text('additional_notes')->nullable();
            $table->timestamps();

            $table->foreign('appointment_id')->references('id')->on('appointments')->onDelete('cascade');
            $table->foreign('cancellation_reason_id')->references('id')->on('cancellation_reasons')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('appointment_cancellation_reasons');
        Schema::dropIfExists('cancellation_reasons');
    }
};
