<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-2A — membership -> role assignment.
     *
     * A membership may hold more than one tenant role, but the roles it holds
     * are always scoped to that one membership's tenant (see
     * App\Platform\Access\PermissionChecker). Tenant A roles therefore can never
     * authorize Tenant B.
     */
    public function up(): void
    {
        Schema::create('membership_roles', function (Blueprint $table) {
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['membership_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_roles');
    }
};
