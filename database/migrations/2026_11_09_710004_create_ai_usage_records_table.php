<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — immutable AI usage records (CENTRAL database).
     *
     * One row per CAPTURED billable inference (never per request): the usage
     * accounting behind tenant AI billing. `idempotency_key` is UNIQUE so a
     * completed job is only ever billed once. Only non-identifying metadata is
     * stored (opaque job reference, provider/model, units, price snapshot) — no
     * patient identity, image or clinical finding.
     */
    public function up(): void
    {
        Schema::create('ai_usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wallet_id')->constrained('tenant_ai_wallets')->cascadeOnDelete();
            $table->string('job_reference');
            $table->string('provider');
            $table->string('model_name')->nullable();
            $table->string('model_version')->nullable();
            $table->string('pricing_rule_ref')->nullable();
            $table->unsignedInteger('unit_price_credits');
            $table->unsignedInteger('credit_units');
            $table->string('idempotency_key')->unique();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('job_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
    }
};
