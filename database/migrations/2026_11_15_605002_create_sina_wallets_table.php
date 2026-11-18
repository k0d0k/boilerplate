<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 2 — per-patient SINA consultation wallets (CENTRAL database).
     *
     * One wallet per (tenant, patient), holding INTEGER credit units only. This
     * is the PATIENT SINA consultation wallet and is deliberately separate from
     * the tenant AI top-up wallet (`tenant_ai_wallets`) — no cross-spending is
     * possible. `patient_id` is a tenant-local id stored as a plain string (no
     * cross-database foreign key), so no clinical data ever lives centrally.
     */
    public function up(): void
    {
        Schema::create('sina_wallets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->bigInteger('balance_credits')->default(0);
            $table->bigInteger('reserved_credits')->default(0);
            $table->bigInteger('lifetime_consumed_credits')->default(0);
            $table->string('status')->default('active');
            $table->string('currency')->default('sina_credit');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sina_wallets');
    }
};
