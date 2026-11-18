<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-4 — per-scope module installation / enablement state.
     *
     * A module enabled for Tenant A is not automatically enabled for Tenant B:
     * every row is bound to an explicit scope (`brand` | `tenant` | `branch`)
     * and an opaque scope key. The `platform` scope is reserved for the
     * catalog-level switch held in {@see module_registry}.
     */
    public function up(): void
    {
        Schema::create('module_installations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('module_key');
            $table->string('scope');
            $table->string('scope_key')->default('');
            $table->boolean('is_installed')->default(false);
            $table->string('status')->default('disabled');
            $table->string('health')->default('unknown');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['module_key', 'scope', 'scope_key']);
            $table->index(['module_key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_installations');
    }
};
