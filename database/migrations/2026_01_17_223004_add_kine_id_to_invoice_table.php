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
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('kine_id')->nullable()->after('id');
            $table->uuid('appointment_id')->nullable()->after('kine_id');

            $table->foreign('kine_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null')
                  ->onUpdate('cascade');

            $table->foreign('appointment_id')
                  ->references('id')
                  ->on('appointments')
                  ->onDelete('set null')
                  ->onUpdate('cascade');

            $table->index('kine_id','appointment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['kine_id']);

            $table->dropColumn('kine_id');
        });
    }
};
