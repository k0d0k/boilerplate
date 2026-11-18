<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 2 — tenant-specific SINA AI provider configuration (CENTRAL
     * database).
     *
     * A tenant configures which OpenAI-compatible or Ollama endpoint the SINA
     * consultation chat should use. The row lives CENTRALLY (never in a tenant
     * clinical database) so the platform can govern inference routing, and the
     * credential is stored ENCRYPTED at rest (`api_key`).
     *
     * At most one row per (tenant, provider); `is_default` marks the provider
     * the resolver prefers when a tenant configures more than one.
     */
    public function up(): void
    {
        Schema::create('ai_provider_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // ollama | openai_compatible
            $table->string('provider');
            $table->string('label')->nullable();
            $table->string('base_url');
            $table->string('model');
            // Encrypted at rest by the model's `encrypted` cast.
            $table->text('api_key')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_configs');
    }
};
