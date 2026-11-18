<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A-2 provisional identity attributes.
     *
     * `role` maps an identity to exactly one panel (see PanelAccessPolicy);
     * `is_active` lets an identity be suspended so panel access fails closed.
     * This is intentionally minimal; the real membership/entitlement model
     * arrives in Phase 1B and the tenant-bound model in Phase 2.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
