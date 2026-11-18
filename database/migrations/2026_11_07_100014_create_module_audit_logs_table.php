<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-4 — module lifecycle / authorization audit trail.
     *
     * Minimal, redacted records only (module key, action, result, scoped
     * references). No clinical or patient payload is ever written here.
     */
    public function up(): void
    {
        Schema::create('module_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('module_key')->nullable();
            $table->string('action');
            $table->string('result')->default('allowed');
            $table->string('reason')->nullable();
            $table->string('scope')->nullable();
            $table->string('scope_key')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['module_key', 'action']);
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_audit_logs');
    }
};
