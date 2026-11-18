<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Commerce\Services\MerchantRegistry;

return new class extends Migration
{
    /**
     * WS8.A — link an order to its merchant-of-record.
     *
     * The column is NULLABLE and additive: existing orders keep a null merchant
     * (their historical settlement is unchanged — financial amounts are never
     * rewritten). When a merchant is recorded, it is the party that legally
     * collects for this order, so a payment can never pool funds across
     * unrelated partners.
     *
     * A plain ulid string + index (no database-level foreign key): historical
     * orders may reference a merchant that is later deactivated, and the
     * isolated tenant database must stay portable across SQLite/MySQL. Integrity
     * is enforced in the domain (see {@see MerchantRegistry}).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->ulid('merchant_id')->nullable()->after('patient_id');
            $table->index('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['merchant_id']);
            $table->dropColumn('merchant_id');
        });
    }
};
