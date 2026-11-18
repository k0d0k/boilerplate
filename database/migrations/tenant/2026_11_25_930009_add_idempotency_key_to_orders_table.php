<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS8.B — a per-tenant idempotency key for order placement.
     *
     * A POS "take payment" (or a patient checkout) retried by a double-click or
     * a flaky network must never create a SECOND order and reserve stock twice.
     * The caller supplies a stable key for the checkout attempt; the unique
     * index makes a duplicate placement fail closed and {@see
     * \Modules\Commerce\Services\OrderService} returns the FIRST order instead.
     *
     * NULLABLE: legacy orders and any placement that does not supply a key keep
     * a null value (SQLite/MySQL allow many NULLs under a unique index), so this
     * is fully additive and rewrites no existing order.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('order_number');
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
