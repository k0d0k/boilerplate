<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS6.B — scoped agent ingestion credentials (CENTRAL database).
     *
     * A credential authenticates ONE operator/device and binds it to EXACTLY ONE
     * tenant, branch and editable examination, so the ingest boundary is the
     * credential itself: a device can never choose a tenant, patient or
     * examination from untrusted input. Only the SHA-256 hash of the bearer
     * secret is ever stored, the credential is short lived (`expires_at`) and it
     * can be revoked (`revoked_at`); all three are re-checked on every request
     * and fail closed.
     *
     * `examination_id` / `branch_id` are TENANT-LOCAL ids stored as plain
     * strings (no cross-database foreign key), mirroring the examination table.
     */
    public function up(): void
    {
        Schema::create('lba_ingest_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('examination_id');
            $table->string('branch_id');
            $table->string('name')->nullable();
            $table->string('token', 64)->unique();
            $table->json('abilities')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'examination_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_ingest_credentials');
    }
};
