<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A — explicit patient release for a report version (TENANT database).
     *
     * Approval and patient release are DELIBERATELY separate facts: a reviewer
     * signing an assessment must never, on its own, hand the document to the
     * patient. `released_at` / `released_by` record the authorized clinic
     * identity that explicitly released THIS exact version to the patient portal.
     *
     * Both columns are nullable and default to NULL, so every historical
     * approved report stays unreleased until a human deliberately releases it.
     */
    public function up(): void
    {
        Schema::table('lba_report_versions', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('approved_at');
            $table->string('released_by')->nullable()->after('released_at');
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('lba_report_versions', function (Blueprint $table) {
            $table->dropIndex(['released_at']);
            $table->dropColumn(['released_at', 'released_by']);
        });
    }
};
