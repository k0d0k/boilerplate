<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — per-tenant AI credit wallets (CENTRAL database).
     *
     * One wallet per tenant, holding INTEGER credit units only (never floats).
     * `available_credits` and `reserved_credits` are separate projections so a
     * reservation moves credits available→reserved ONCE and a release/capture
     * moves them back exactly once; the available projection can never go
     * negative.
     *
     * This is the TENANT AI top-up wallet and is deliberately separate from the
     * patient SINA consultation wallet — no cross-spending is possible.
     *
     * No patient identity, image or clinical finding is ever stored here: only
     * opaque tenant/job references and integer credit accounting.
     */
    public function up(): void
    {
        Schema::create('tenant_ai_wallets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->bigInteger('available_credits')->default(0);
            $table->bigInteger('reserved_credits')->default(0);
            $table->bigInteger('lifetime_consumed_credits')->default(0);
            $table->string('status')->default('active');
            $table->string('currency')->default('ai_credit');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_wallets');
    }
};
