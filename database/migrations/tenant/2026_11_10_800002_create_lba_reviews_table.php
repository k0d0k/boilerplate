<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7B — one human review decision on an assessment (TENANT database).
     *
     * Append-only: every return / approve / reject action records WHO decided,
     * WHEN, and with what note, so the review history is fully auditable. The
     * decision is a HUMAN action only — no row is ever written by an AI process.
     * A review belongs to exactly one assessment of the verified tenant.
     */
    public function up(): void
    {
        Schema::create('lba_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_id')->constrained('lba_assessments')->cascadeOnDelete();
            $table->string('reviewer_id');
            $table->json('reviewer_snapshot')->nullable();
            $table->string('decision');
            $table->text('notes')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index('assessment_id');
            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_reviews');
    }
};
