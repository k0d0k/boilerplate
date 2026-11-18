<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS9.A — knowledge collections (CENTRAL database).
     *
     * A collection groups knowledge documents that share one explicit SCOPE:
     * `platform` (general educational reference curated by the Principal and
     * globally published) or `tenant` (clinic reference material visible ONLY
     * within one tenant). Every row is bound to an opaque `scope_key` — the
     * tenant ULID for a tenant collection, an empty string for a platform
     * collection — so isolation is a single indexed equality check that can
     * never be spoofed by client input (the same scope/scope_key convention the
     * module installation tables use).
     *
     * These tables hold NON-PATIENT reference metadata only. No clinical record
     * is ever written here, so tenant isolation is a metadata filter rather than
     * a physical database split.
     */
    public function up(): void
    {
        Schema::create('knowledge_collections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // platform | tenant
            $table->string('scope')->default('tenant');
            // Tenant ULID for a tenant collection, '' for a platform collection.
            $table->string('scope_key')->default('');
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('language')->default('id');
            $table->boolean('is_active')->default(true);
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_key', 'key']);
            $table->index(['scope', 'scope_key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_collections');
    }
};
