<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Commerce\Services\InventoryService;

return new class extends Migration
{
    /**
     * WS3 — branch-level inventory (Modules/Commerce).
     *
     * One stock row per (product, branch): a tenant's stock physically sits at a
     * branch. The tenant-wide total is the sum across branches and is never
     * stored twice. Both the product and the branch live in the SAME tenant
     * database, so a normal foreign key is safe here.
     *
     * `quantity` is a non-negative integer: stock is only ever moved through
     * {@see InventoryService}, which refuses to issue
     * more than is on hand (negative stock is impossible).
     */
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->ulid('branch_id');
            $table->integer('quantity')->default(0);
            $table->integer('reorder_level')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'branch_id']);
            $table->index('branch_id');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
