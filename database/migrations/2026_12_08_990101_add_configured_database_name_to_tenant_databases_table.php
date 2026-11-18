<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 (v3.3) — per-partner MANUAL database name.
     *
     * Before this column, the only way to point ONE partner at an explicitly
     * named physical database was the single-tenant `TENANT_DB` +
     * `TENANT_DB_TENANT_ID` `.env` override, which cannot describe more than
     * one partner. This central, non-clinical column records the physical MySQL
     * name an operator typed on the Principal "Tambah Mitra" form, so every
     * partner can own a differently named database WITHOUT any `.env` entry.
     *
     * It stays NULL for every partner that never used the manual form — a
     * legacy partner keeps resolving exactly as before (the derived
     * `<account>_<prefix><ulid>` name, or the single-tenant `.env` override),
     * so nothing is re-pointed by this change.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tenant_databases', 'configured_database_name')) {
            return;
        }

        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->string('configured_database_name')->nullable()->after('database_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenant_databases', 'configured_database_name')) {
            return;
        }

        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->dropColumn('configured_database_name');
        });
    }
};
