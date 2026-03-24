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
        Schema::create('patient_documents', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('patient_id', 36)->index('patient_documents_patient_id_foreign');
            $table->string('title');
            $table->enum('type', ['medical_report', 'prescription', 'xray', 'scan', 'other']);
            $table->string('file_path');
            $table->string('file_name');
            $table->integer('file_size');
            $table->string('file_type');
            $table->text('notes')->nullable();
            $table->char('uploaded_by', 36)->index('patient_documents_uploaded_by_foreign');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
    }
};
