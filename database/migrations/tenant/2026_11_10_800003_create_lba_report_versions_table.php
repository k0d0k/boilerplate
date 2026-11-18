<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7B — immutable, versioned PDF reports (TENANT database).
     *
     * A version is WRITE-ONCE: once a PDF is generated its bytes are stored on a
     * PRIVATE disk and this row is never updated (a revision appends version
     * N+1). `checksum` is the SHA-256 of the exact stored bytes, so tampering or
     * corruption is detectable. `brand_snapshot`, `branch_snapshot` and
     * `reviewer_snapshot` capture the delivered identity/approval at generation
     * time, so a later rename, re-assignment or branch change cannot rewrite a
     * historical report. The PDF is only ever read back through an authenticated,
     * tenant- and branch-scoped endpoint — there is no public URL.
     */
    public function up(): void
    {
        Schema::create('lba_report_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_id')->constrained('lba_assessments')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('generated');
            $table->string('locale', 8);

            $table->string('brand_id')->nullable();
            $table->json('brand_snapshot')->nullable();
            $table->string('branch_id')->nullable();
            $table->json('branch_snapshot')->nullable();

            $table->string('reviewer_id')->nullable();
            $table->json('reviewer_snapshot')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->string('checksum', 64);
            $table->string('disk');
            $table->string('object_key');
            $table->string('mime_type')->default('application/pdf');
            $table->unsignedBigInteger('size_bytes');

            $table->string('generated_by')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['assessment_id', 'version']);
            $table->index('assessment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_report_versions');
    }
};
