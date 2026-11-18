<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS4 — per-tenant subscription / licence.
     *
     * Binds exactly ONE tenant to ONE plan for a billing period. `reference` is
     * the opaque value a module entitlement records as `subscription_reference`
     * so the FeatureGate can verify the live subscription without trusting
     * client input. Lifecycle metadata only — never clinical data.
     */
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference')->unique();
            $table->ulid('tenant_id');
            $table->ulid('plan_id');
            $table->string('status')->default('pending');
            $table->unsignedInteger('seats')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
