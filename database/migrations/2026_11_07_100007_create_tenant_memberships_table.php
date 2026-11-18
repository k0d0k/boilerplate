<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-2A — tenant membership (identity <-> tenant binding).
     *
     * One membership per user/tenant pair. `status` makes the lifecycle explicit
     * and defaults to `invited`, so a new membership grants nothing until it is
     * explicitly activated (invited|active|suspended|revoked). `revoked_at`
     * records when access was removed.
     */
    public function up(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('status')->default('invited');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
    }
};
