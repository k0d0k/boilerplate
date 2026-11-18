<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 2 — immutable SINA wallet ledger (CENTRAL database).
     *
     * Every movement of SINA credits is ONE immutable entry (no `updated_at`).
     * `idempotency_key` is UNIQUE, so a repeated reserve/capture/release can
     * never post twice and therefore can never double-charge or double-refund.
     * `consultation_id` is an opaque tenant-local reference — no clinical
     * content is stored here.
     */
    public function up(): void
    {
        Schema::create('sina_wallet_ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wallet_id')->constrained('sina_wallets')->cascadeOnDelete();
            $table->ulid('patient_id');
            // grant | reserve | capture | release | adjust
            $table->string('action');
            $table->bigInteger('credit_units');
            $table->bigInteger('balance_after');
            $table->bigInteger('reserved_after');
            $table->string('consultation_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('actor_reference')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'patient_id']);
            $table->index('wallet_id');
            $table->index('consultation_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sina_wallet_ledger_entries');
    }
};
