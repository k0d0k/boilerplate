<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-2A — tenant <-> brand relationship.
     *
     * Many-to-many: one tenant may operate under several brands and one brand
     * may span several tenants. `is_primary` marks the tenant's default brand
     * without collapsing the two concepts (ARCH-05).
     */
    public function up(): void
    {
        Schema::create('tenant_brands', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['tenant_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_brands');
    }
};
