<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — central payment intent registry (Modules/Commerce).
     *
     * A gateway webhook arrives with NO tenant context: it carries only the
     * provider's reference. This CENTRAL, non-clinical index maps a gateway
     * reference to the tenant and order that produced it, so the secure webhook
     * handler can resolve the correct tenant database and settle the right
     * order. No clinical data is stored here — only ids, the amount and status.
     *
     * `idempotency_key` and `[provider, provider_reference]` are UNIQUE, so a
     * retried charge or a replayed webhook resolves to the ONE intent that
     * already exists.
     */
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_id');
            $table->string('order_id');
            $table->string('order_number');
            $table->string('provider');
            $table->string('provider_reference')->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('initiated');
            $table->string('idempotency_key')->unique();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index('tenant_id');
            $table->index('order_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
