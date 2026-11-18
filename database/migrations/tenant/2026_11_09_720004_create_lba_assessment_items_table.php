<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7A — one assessed indicator of an assessment (TENANT database).
     *
     * `category` stores ONE explicit value (green | yellow | red | unassessed) —
     * never three independent booleans — and defaults to `unassessed`: an item
     * that has not been evaluated is NEVER assumed green.
     *
     * The indicator labels and catalog version are SNAPSHOT onto the item, so a
     * later catalog edit cannot rewrite what was assessed historically. These
     * categories are internal observation/review priorities, not disease risk or
     * diagnostic classifications.
     */
    public function up(): void
    {
        Schema::create('lba_assessment_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_id')->constrained('lba_assessments')->cascadeOnDelete();
            $table->string('indicator_key');
            $table->string('catalog_version');
            $table->text('source_label');
            $table->string('observation_label')->nullable();
            $table->string('source_column')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('category')->default('unassessed');
            $table->text('notes')->nullable();
            $table->string('assessor_id')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('provenance')->default('human');
            $table->timestamps();

            $table->unique(['assessment_id', 'indicator_key']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_assessment_items');
    }
};
