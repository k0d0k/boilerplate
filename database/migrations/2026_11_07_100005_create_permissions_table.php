<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-2A — permission catalog.
     *
     * One row per canonical permission key (see App\Platform\Access\PermissionKey).
     * `scope` is platform|tenant|patient and decides which assignment source may
     * ever grant the permission (a platform permission can never be granted by a
     * tenant membership, and vice versa).
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('scope');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
