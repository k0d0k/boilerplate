<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2B — tenant branches.
     *
     * Lives inside the tenant's isolated clinical database: several branches of
     * one tenant share this database, and a branch NEVER duplicates the tenant
     * or brand identity (there is no tenant_id / brand_id column — the tenant is
     * implied by which database the row lives in; ARCH-05).
     *
     * Deactivation is non-destructive: `status` becomes non-active and
     * `deactivated_at` records when. Branches are never deleted.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('timezone')->default('Asia/Jakarta');
            $table->string('status')->default('active');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
