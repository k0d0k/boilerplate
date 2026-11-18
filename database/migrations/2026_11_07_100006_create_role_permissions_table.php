<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-2A — role -> permission assignment.
     *
     * The single source of truth for "what a role may do". Seeded from
     * App\Platform\Access\RbacCatalog, which follows docs/ROLE_PERMISSION_MATRIX.md.
     */
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
