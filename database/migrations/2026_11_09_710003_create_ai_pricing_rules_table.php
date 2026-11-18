<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — configurable AI pricing rules (CENTRAL database).
     *
     * Pricing per provider/model/unit is EXPLICIT and configurable; no universal
     * AI token price is invented. A rule with a null `model_version` matches any
     * version of that model. A billable job snapshots the resolved rule's unit
     * price on the job row, so a later pricing change never re-prices a job that
     * already reserved credits.
     */
    public function up(): void
    {
        Schema::create('ai_pricing_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider');
            $table->string('model_name');
            $table->string('model_version')->nullable();
            // per_job | per_image
            $table->string('unit')->default('per_job');
            $table->unsignedInteger('unit_price_credits');
            $table->boolean('active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider', 'model_name', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pricing_rules');
    }
};
