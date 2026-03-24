// database/migrations/xxxx_xx_xx_xxxxxx_create_security_events_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->string('event_type');
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('location')->nullable();
            $table->foreignUuid('device_id')->nullable()->constrained('user_devices')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->string('status')->default('info'); 
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('security_events');
    }
};
