<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — payment transaction history (Modules/Commerce).
     *
     * Every payment attempt against an order is appended here: a POS cash
     * settlement, a manual bank transfer, or a hosted gateway charge
     * (Midtrans / iPaymu / PayPal). Rows are append-only — a failed attempt is
     * kept as history, never overwritten.
     *
     * `idempotency_key` is UNIQUE so a retried request (or a duplicated gateway
     * webhook) can only ever create ONE transaction — duplicate payments are
     * impossible to record twice. `[provider, provider_reference]` is likewise
     * unique, so a gateway reference maps to at most one local transaction.
     *
     * `request_payload` / `response_payload` capture the sanitised exchange with
     * the gateway (credentials are never written here).
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('order_id');
            $table->ulid('invoice_id')->nullable();
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->string('method')->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('initiated');
            $table->string('idempotency_key')->unique();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index('order_id');
            $table->index('invoice_id');
            $table->index('status');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
