<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — tenant product catalog (Modules/Commerce).
     *
     * A product belongs to ONE tenant (the database is the tenant boundary) and
     * may carry a BRAND label (`brand_id`). A brand is a central commercial
     * identity (ARCH-05), so `brand_id` is a plain string that deliberately
     * carries NO cross-database foreign key — the central and tenant databases
     * are separate connections. The brand is validated in the application layer.
     *
     * `base_currency` + `base_price` are the product's primary price. Currency
     * is ALWAYS explicit (IDR or MYR); additional currency-specific prices live
     * in `product_prices`.
     *
     * `track_inventory` decides whether checkout enforces stock at all; a
     * non-stock item (e.g. a service) never blocks a sale. Archiving is
     * non-destructive.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Central brand id: a plain string, deliberately NOT a foreign key
            // (cross-database references are forbidden).
            $table->string('brand_id')->nullable();
            $table->ulid('category_id')->nullable();
            $table->string('sku')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->default('pcs');
            $table->string('kind')->default('product');
            $table->char('base_currency', 3)->default('IDR');
            $table->decimal('base_price', 14, 2)->default(0);
            $table->boolean('track_inventory')->default(true);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('brand_id');
            $table->index('category_id');
            $table->index('status');
            $table->index('kind');

            $table->foreign('category_id')->references('id')->on('product_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
