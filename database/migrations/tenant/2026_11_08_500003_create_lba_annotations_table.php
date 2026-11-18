<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — manual LBA annotations (Modules/LBA).
     *
     * `job_id` is NULLABLE on purpose: a purely manual annotation is authored
     * by a human with no AI job at all and the manual workflow must function
     * with no AI server running. `provenance` distinguishes `human` from `ai`,
     * and `geometry` stores coordinates NORMALISED against the original image
     * (0..1), so stored positions never move when the viewer zooms or pans.
     */
    public function up(): void
    {
        Schema::create('lba_annotations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('examination_id');
            $table->ulid('image_id');
            $table->string('job_id')->nullable();
            $table->string('provenance')->default('human');
            $table->string('type');
            $table->json('geometry');
            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->string('actor_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index('examination_id');
            $table->index('image_id');
            $table->index('provenance');

            $table->foreign('examination_id')->references('id')->on('lba_examinations')->cascadeOnDelete();
            $table->foreign('image_id')->references('id')->on('lba_images')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_annotations');
    }
};
