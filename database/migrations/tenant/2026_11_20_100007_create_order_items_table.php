<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — order line items (Modules/Commerce).
     *
     * Each line snapshots the product NAME, SKU and unit price AT THE TIME OF
     * SALE. The catalog may later rename or reprice a product; the historical
     * order must not change, so the line never depends on the live product row
     * for its money. `product_id` is kept for reporting and is nulled (not
     * cascaded) if a product is ever removed.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('order_id');
            $table->ulid('product_id')->nullable();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->integer('quantity');
            $table->decimal('line_total', 14, 2);
            $table->char('currency', 3);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
