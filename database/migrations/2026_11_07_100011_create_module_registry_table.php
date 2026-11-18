<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-4 — central module catalog.
     *
     * Records the declared capabilities of every module the platform knows
     * about and acts as the platform-wide master switch (installed / health /
     * status). Only module *metadata* lives here: the central database never
     * stores clinical or patient records (see docs/DATA_ARCHITECTURE.md).
     *
     * Brand != Tenant != Branch: brand, tenant and branch scoping is expressed
     * through the string `scope` columns on {@see module_installations} and
     * {@see module_entitlements}, never by collapsing the three concepts.
     */
    public function up(): void
    {
        Schema::create('module_registry', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('module_key')->unique();
            $table->string('name');
            $table->string('version');
            $table->string('min_platform_version');
            $table->json('allowed_panels');
            $table->json('required_permissions');
            $table->json('required_dependencies');
            $table->json('supported_locales');
            $table->json('scope_compatibility');
            $table->string('entitlement_requirement')->default('entitlement');
            $table->boolean('is_installed')->default(false);
            $table->string('health')->default('unknown');
            $table->string('status')->default('disabled');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('health');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_registry');
    }
};
