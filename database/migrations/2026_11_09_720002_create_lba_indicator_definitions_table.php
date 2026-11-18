<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7A — one indicator DEFINITION of a catalog version (CENTRAL db).
     *
     * `source_label` preserves the ORIGINAL legacy label (and its column/order)
     * exactly as transcribed, so nothing is silently rewritten or removed.
     * `observation_label` is the reviewed terminology layer: it stays NULL and
     * `validation_status` stays `unvalidated`/`reference_only` until an
     * authorized clinical review approves an appropriate use. A legacy label is
     * therefore never a patient-facing diagnostic finding on its own.
     *
     * `key` is a stable, opaque indicator key an assessment item is stored
     * against; renaming a label never changes a historical item.
     */
    public function up(): void
    {
        Schema::create('lba_indicator_definitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('catalog_version_id')
                ->constrained('lba_indicator_catalog_versions')
                ->cascadeOnDelete();
            $table->string('key');
            $table->text('source_label');
            $table->string('source_column')->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('observation_label')->nullable();
            $table->string('validation_status')->default('unvalidated');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['catalog_version_id', 'key']);
            $table->unique(['catalog_version_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_indicator_definitions');
    }
};
