<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-2A — role catalog.
     *
     * One row per canonical role key (see App\Platform\Access\RoleKey). `scope`
     * is one of platform|tenant|patient|machine and `panel` records the single
     * human panel a role belongs to (null for the machine-only ai_service role).
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('scope');
            $table->string('panel')->nullable();
            $table->boolean('is_machine')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
