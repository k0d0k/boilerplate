<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-2A — central brand catalog.
     *
     * A brand is a commercial identity (Babul Syiffa / NuBiom / Biodam) and is
     * deliberately NOT a tenant and NOT a branch (ARCH-05). It lives in the
     * central database and carries no clinical data.
     */
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
