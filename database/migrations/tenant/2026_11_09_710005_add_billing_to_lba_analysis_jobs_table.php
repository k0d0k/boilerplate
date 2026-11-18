<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — durable billing state on the tenant-owned AI job (TENANT db).
     *
     * The AI job and its detections stay in the tenant database; the wallet and
     * ledger live in the CENTRAL database. Because no SQL transaction spans the
     * two databases, the job carries its own durable billing state so an
     * interrupted cross-database operation can be reconciled:
     *
     *  - `billable`         — the job's immutable billability decision;
     *  - `pricing_rule_ref` — the pricing rule captured at reservation time;
     *  - `unit_price_credits` — the immutable per-job price SNAPSHOT;
     *  - `credits_state`    — null | reserved | captured | released.
     *
     * A crash between the tenant row and the central ledger therefore never
     * silently loses credits: the state is repaired by the reconciliation
     * command using the central ledger as the source of truth.
     */
    public function up(): void
    {
        Schema::table('lba_analysis_jobs', function (Blueprint $table) {
            $table->boolean('billable')->default(false)->after('error_code');
            $table->string('pricing_rule_ref')->nullable()->after('billable');
            $table->unsignedInteger('unit_price_credits')->nullable()->after('pricing_rule_ref');
            $table->string('credits_state')->nullable()->after('unit_price_credits');

            $table->index('credits_state');
        });
    }

    public function down(): void
    {
        Schema::table('lba_analysis_jobs', function (Blueprint $table) {
            $table->dropIndex(['credits_state']);
            $table->dropColumn(['billable', 'pricing_rule_ref', 'unit_price_credits', 'credits_state']);
        });
    }
};
