<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 2 — immutable SINA usage records (CENTRAL database).
     *
     * One row per CAPTURED billable SINA turn: provider/model, integer token
     * counters and the charged credits. `idempotency_key` is UNIQUE so a
     * completed turn is billed exactly once. Only non-identifying accounting
     * metadata is stored — no message text, no patient identity.
     */
    public function up(): void
    {
        Schema::create('sina_usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('wallet_id')->constrained('sina_wallets')->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->string('consultation_id')->nullable();
            $table->string('provider');
            $table->string('model_name')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('credit_units');
            $table->string('idempotency_key')->unique();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'patient_id']);
            $table->index('consultation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sina_usage_records');
    }
};
