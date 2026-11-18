<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 (v3.3) — reversible partner removal.
     *
     * "Hapus Mitra" on Principal is a SOFT delete: the central registry row is
     * flagged removed, so the partner's access stops immediately (every tenant
     * query uses the default scope), while the central child rows — memberships,
     * subscriptions, brand links and, crucially, the `tenant_databases` registry
     * row — are PRESERVED.
     *
     * Preserving the registry row is what keeps a removed partner's clinical
     * schema from ever being re-opened: `tenant_databases.database_name` stays
     * UNIQUE, so the same physical database cannot be registered for a new
     * partner until an operator deliberately purges the old registry row in a
     * controlled staging reset. The physical cPanel schema itself is never
     * dropped or emptied by the application.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tenants', 'deleted_at')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenants', 'deleted_at')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
