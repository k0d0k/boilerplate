<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-4 — per-scope module entitlement grants.
     *
     * An entitlement is the explicit, server-verified permission for one scope
     * (brand | tenant | branch) to use one module. Absence of a row means "not
     * entitled", so access fails closed. A grant never implies a subscription:
     * `subscription_required` records whether a live subscription must also be
     * verified by a subscription provider before the module may execute.
     */
    public function up(): void
    {
        Schema::create('module_entitlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('module_key');
            $table->string('scope');
            $table->string('scope_key');
            $table->string('status')->default('pending');
            $table->boolean('subscription_required')->default(false);
            $table->string('subscription_reference')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['module_key', 'scope', 'scope_key']);
            $table->index(['module_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_entitlements');
    }
};
