<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-3 — per-identity language preference.
     *
     * Stores the authenticated user's explicit language choice (one of the
     * canonical supported locales). It is nullable so an identity without a
     * saved preference falls through to the approved resolution order
     * (permitted brand default -> verified tenant default -> platform default).
     *
     * This is a display preference only: it never influences authorization,
     * membership, panel access or any regional setting (LOCALIZATION.md).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
