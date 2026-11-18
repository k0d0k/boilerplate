<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 — AI detection SUGGESTIONS (Modules/LBA).
     *
     * These rows are deliberately kept SEPARATE from `lba_annotations`: an AI
     * output is an unreviewed suggestion (`provenance = ai`) that can never
     * overwrite, edit or delete a human-authored annotation, and can never be
     * approved or published. `geometry` is NORMALISED against the original image
     * (0..1) exactly like a manual annotation, so a suggestion stays anchored to
     * the same pixels at any zoom. `quality_flags` carries provenance/quality
     * markers (for the synthetic adapter: `synthetic`, `not_a_diagnosis`).
     */
    public function up(): void
    {
        Schema::create('lba_detections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('examination_id');
            $table->ulid('image_id');
            $table->ulid('job_id');
            $table->string('provenance')->default('ai');
            $table->string('label');
            $table->json('geometry');
            $table->float('confidence');
            $table->string('model_name')->nullable();
            $table->string('model_version')->nullable();
            $table->json('quality_flags')->nullable();
            $table->timestamps();

            $table->index('examination_id');
            $table->index('image_id');
            $table->index('job_id');

            $table->foreign('examination_id')->references('id')->on('lba_examinations')->cascadeOnDelete();
            $table->foreign('image_id')->references('id')->on('lba_images')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('lba_analysis_jobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_detections');
    }
};
