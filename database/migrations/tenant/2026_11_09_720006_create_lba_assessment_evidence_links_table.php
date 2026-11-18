<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7A — evidence attached to an assessed indicator (TENANT database).
     *
     * Evidence refers ONLY to records of the SAME examination and therefore the
     * same tenant: an original image, a human annotation or an (unreviewed) AI
     * detection. The `provenance` snapshot keeps human and AI evidence visually
     * and semantically distinct — an AI detection is never silently promoted to
     * a verified finding.
     */
    public function up(): void
    {
        Schema::create('lba_assessment_evidence_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_id')->constrained('lba_assessments')->cascadeOnDelete();
            $table->string('indicator_key');
            $table->string('evidence_type');
            $table->string('evidence_id');
            $table->string('provenance')->default('human');
            $table->text('note')->nullable();
            $table->string('linked_by')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'indicator_key', 'evidence_type', 'evidence_id'], 'lba_assessment_evidence_unique');
            $table->index('assessment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_assessment_evidence_links');
    }
};
