<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS8.A — tenant merchant-of-record mapping.
     *
     * A merchant records WHO legally collects money for a tenant/brand in one
     * currency, and WHERE that money settles. It exists so a clinic can never
     * inadvertently take payment through another partner's entity: the mapping
     * is explicit, auditable (`created_by`) and scoped to the isolated tenant
     * database.
     *
     * The merchant row carries NO secret — provider credentials stay central.
     * `settlement_provider` names which configured gateway this merchant may
     * settle through and `settlement_reference` is the merchant's own
     * (non-secret) account reference at that provider.
     */
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('brand_id')->nullable();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->char('currency', 3);
            $table->char('country', 2)->nullable();
            $table->string('tax_id')->nullable();
            $table->string('invoice_issuer')->nullable();
            $table->string('settlement_provider')->nullable();
            $table->string('settlement_reference')->nullable();
            $table->string('status')->default('active');
            $table->string('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('brand_id');
            $table->index('currency');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
