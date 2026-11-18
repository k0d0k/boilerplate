<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central storage for the per-tenant MySQL credentials used in MANUAL
     * database mode.
     *
     * A central, non-clinical row: it records only the restricted MySQL user the
     * application authenticates as for one tenant's isolated database, plus its
     * password. The password is stored ENCRYPTED (see the model's cast), is
     * write-only on the settings surface and is never rendered back. The
     * database host/port are NOT stored here — manual mode uses the server's
     * tenant-connection configuration (`TENANT_DB_HOST` / `TENANT_DB_PORT`).
     */
    public function up(): void
    {
        Schema::create('tenant_database_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_database_credentials');
    }
};
