<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7B patch — snapshot the terminology VALIDATION status and its
     * approval provenance onto each assessment item (TENANT database).
     *
     * A non-empty `observation_label` is NOT, on its own, evidence that a
     * finding has been medically reviewed. An item may only become a
     * patient-facing clinical finding when it carries an EXPLICIT approved
     * terminology status AND a trustworthy approval provenance (who approved it
     * and when), snapshotted here from the central catalog definition so a later
     * catalog edit can never silently promote a historical item.
     *
     * Existing items predate the approval workflow: every one of them was
     * transcribed from the legacy reference form and is therefore backfilled to
     * `reference_only`, preserving its historical reference-only meaning.
     */
    public function up(): void
    {
        Schema::table('lba_assessment_items', function (Blueprint $table) {
            $table->string('terminology_status')->nullable()->after('observation_label');
            $table->string('terminology_approved_by')->nullable()->after('terminology_status');
            $table->timestamp('terminology_approved_at')->nullable()->after('terminology_approved_by');
        });

        // Every pre-existing item is legacy reference terminology: it carries no
        // reviewed observation label and no approval, so it stays reference-only.
        DB::table('lba_assessment_items')
            ->whereNull('terminology_status')
            ->update(['terminology_status' => 'reference_only']);
    }

    public function down(): void
    {
        Schema::table('lba_assessment_items', function (Blueprint $table) {
            $table->dropColumn(['terminology_status', 'terminology_approved_by', 'terminology_approved_at']);
        });
    }
};
