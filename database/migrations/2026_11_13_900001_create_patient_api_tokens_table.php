<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8B — secure patient REST API tokens (CENTRAL database).
     *
     * A token authenticates one patient account AND binds it to ONE clinic
     * (tenant). Only the SHA-256 hash of the bearer secret is ever stored, so a
     * leaked database row can never be replayed. `revoked_at` supports explicit
     * logout/rotation and `expires_at` bounds the token lifetime; both are
     * re-checked on every request and fail closed.
     */
    public function up(): void
    {
        Schema::create('patient_api_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('token', 64)->unique();
            $table->json('abilities')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_api_tokens');
    }
};
