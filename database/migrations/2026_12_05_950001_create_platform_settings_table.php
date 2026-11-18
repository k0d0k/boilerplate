<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central platform settings store (Phase 3 — Global Settings).
 *
 * A small key/value table for the platform-level settings the Principal
 * Super Admin may persist from the UI (default language, cPanel hosting
 * credentials). Secret values (the cPanel API token) are stored ENCRYPTED and
 * are never read back into a response, Livewire state, log line or audit row.
 *
 * Values here are overrides: every key falls back to its existing `.env`
 * configuration so installations that never open Global Settings keep working
 * unchanged. The table is CENTRAL (the platform registry database), never a
 * tenant database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
