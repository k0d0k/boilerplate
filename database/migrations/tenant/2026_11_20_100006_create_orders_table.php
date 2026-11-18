<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — tenant orders (Modules/Commerce).
     *
     * An order is always scoped to ONE branch of the current tenant (the
     * database is the tenant boundary). It stores MONEY as explicit decimals and
     * a single explicit `currency` (IDR or MYR) — never inferred from locale.
     *
     * `patient_id` links the order to a patient when the buyer is a known
     * patient (a POS walk-in may leave it null). `placed_by` is a central user
     * id kept as a plain string (cross-database references are forbidden).
     *
     * Totals are denormalised so an order is a faithful financial snapshot even
     * if the catalog later changes.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('order_number')->unique();
            $table->string('source')->default('pos');
            $table->ulid('branch_id');
            $table->ulid('patient_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->char('currency', 3);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->string('status')->default('pending');
            $table->string('fulfillment_status')->default('pending');
            $table->string('placed_by')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('fulfillment_status');
            $table->index('branch_id');
            $table->index('patient_id');
            $table->index('currency');
            $table->index('source');

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
