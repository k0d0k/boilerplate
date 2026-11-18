<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7A — one digital LBA observation assessment (TENANT database).
     *
     * Tenant-owned and clinical: an assessment belongs to exactly one existing
     * examination of the verified tenant, so it can never be read or written
     * across tenants. The indicator catalog itself is CENTRAL reference
     * metadata; here we only SNAPSHOT the catalog version (and, on each item,
     * the labels) so a later catalog update cannot silently alter a historical
     * assessment.
     *
     * `mode` snapshots the examination mode (manual / ai / hybrid) so ONE common
     * data model serves all three workflows. `status` is draft /
     * submitted_for_review / returned_for_changes / reviewed (reviewed is
     * reserved for Phase 7B). `brand_id` is a CENTRAL brand id stored as a plain
     * string (no cross-database foreign key): brand != tenant != branch.
     *
     * No colour or category here is a validated disease risk — the four
     * categories are internal observation/review priorities only.
     */
    public function up(): void
    {
        Schema::create('lba_assessments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('examination_id')->constrained('lba_examinations')->cascadeOnDelete();
            $table->string('catalog_version');
            $table->string('catalog_version_id')->nullable();
            $table->string('mode');
            $table->string('status')->default('draft');
            $table->string('program_phase')->default('not_specified');
            $table->string('brand_id')->nullable();
            $table->json('brand_snapshot')->nullable();
            $table->string('assessed_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('last_saved_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('examination_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_assessments');
    }
};
