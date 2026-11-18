<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — immutable AI wallet ledger (CENTRAL database).
     *
     * Every movement of credits is recorded as ONE immutable entry (there is no
     * `updated_at`; an entry is never rewritten). `idempotency_key` is UNIQUE,
     * so a repeated reserve/capture/release can never post twice and therefore
     * can never double-charge or double-refund.
     *
     * `job_reference` is the OPAQUE AI job reference — never a job id that could
     * resolve clinical data, and never a patient identity.
     */
    public function up(): void
    {
        Schema::create('tenant_ai_wallet_ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wallet_id')->constrained('tenant_ai_wallets')->cascadeOnDelete();
            // grant | reserve | capture | release | adjust
            $table->string('action');
            // Signed: a reserve/capture is negative on available where relevant.
            $table->bigInteger('credit_units');
            $table->bigInteger('available_after');
            $table->bigInteger('reserved_after');
            $table->string('job_reference')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('actor_reference')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_id');
            $table->index('wallet_id');
            $table->index('job_reference');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_wallet_ledger_entries');
    }
};
