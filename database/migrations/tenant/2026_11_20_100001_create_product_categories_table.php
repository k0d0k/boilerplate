<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — tenant product categories (Modules/Commerce).
     *
     * Lives inside the tenant's isolated database: categories are tenant-local
     * and are never shared across tenants (there is no tenant_id column — the
     * tenant IS the database). A category may nest through `parent_id`; a
     * self-referential parent is set to null on delete so a subtree cannot
     * orphan a row.
     *
     * Deactivation is non-destructive — `status` changes, the row is retained.
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('position')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('parent_id');
            $table->index('status');

            $table->foreign('parent_id')->references('id')->on('product_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
