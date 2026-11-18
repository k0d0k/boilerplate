<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2A — minimal NON-CLINICAL tenant smoke-test table.
     *
     * Lives in each tenant's isolated database and exists only to demonstrate
     * read/write isolation between tenants and that several branches of one
     * tenant share the same tenant database. It carries no patient or clinical
     * data (PRV-01).
     */
    public function up(): void
    {
        Schema::create('tenant_smoke_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('branch_id')->nullable();
            $table->string('label');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_smoke_records');
    }
};
