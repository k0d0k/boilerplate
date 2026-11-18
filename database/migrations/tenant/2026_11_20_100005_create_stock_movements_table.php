<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — immutable stock movement ledger (Modules/Commerce).
     *
     * Every change to a stock level appends ONE row here. A movement is never
     * updated or deleted, so the stock history is auditable and a level can
     * always be reconciled from its movements.
     *
     * `quantity` is the SIGNED delta applied to the level (positive = stock in,
     * negative = stock out) and `balance_after` is the resulting level. A
     * `reference_type` / `reference_id` pair links a movement to the document
     * that caused it (an order, a refund) without a cross-module foreign key.
     *
     * `actor_id` is a central user id: a plain string, deliberately NOT a
     * foreign key (cross-database references are forbidden).
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->ulid('branch_id');
            $table->string('type');
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->string('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('actor_id')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'branch_id']);
            $table->index('type');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
