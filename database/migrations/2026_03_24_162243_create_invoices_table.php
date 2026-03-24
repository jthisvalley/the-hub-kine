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
        Schema::create('invoices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('kine_id', 36)->nullable()->index();
            $table->char('appointment_id', 36)->nullable()->index();
            $table->char('patient_id', 36)->index('invoices_patient_id_foreign');
            $table->char('subscription_id', 36)->nullable()->index('invoices_subscription_id_foreign');
            $table->string('invoice_number', 50)->unique('invoice_number');
            $table->string('service_type')->nullable();
            $table->float('tax')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('amount', 10);
            $table->float('total_amount')->nullable();
            $table->string('currency', 3)->nullable()->default('EUR');
            $table->enum('status', ['draft', 'pending', 'paid', 'overdue', 'uncollectible'])->nullable()->default('draft')->index('idx_invoices_status');
            $table->longText('notes')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable()->index('idx_invoices_due_date');
            $table->text('pdf_url')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status'], 'idx_invoices_patient_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
