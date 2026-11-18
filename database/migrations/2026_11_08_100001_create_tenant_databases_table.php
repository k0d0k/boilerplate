<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2A — central registry of each tenant's isolated clinical database.
     *
     * Central, non-clinical metadata only: which physical database belongs to a
     * tenant and how far provisioning/migration has progressed. No patient or
     * clinical data is ever stored centrally.
     */
    public function up(): void
    {
        Schema::create('tenant_databases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('database_name')->unique();
            $table->string('driver');
            $table->string('status')->default('pending');
            $table->timestamp('last_migrated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_databases');
    }
};
