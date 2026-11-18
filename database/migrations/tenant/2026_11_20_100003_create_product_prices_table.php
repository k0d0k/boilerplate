<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — currency-specific product prices (Modules/Commerce).
     *
     * One row per (product, currency). A tenant operating in both Indonesia
     * (IDR) and Malaysia (MYR) can price a product explicitly in each currency
     * rather than relying on a conversion. The unique key prevents a duplicate
     * price for the same product and currency.
     */
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'currency']);
            $table->index('currency');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
