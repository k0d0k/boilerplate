<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS9.A — immutable knowledge document versions (CENTRAL database).
     *
     * One row per uploaded PDF revision. The file is written to a PRIVATE disk
     * (no public URL); only its opaque storage path is recorded. `sha256` gives
     * checksum de-duplication by scope — re-ingesting the identical bytes in the
     * same scope resolves to the existing document instead of creating a
     * duplicate — and `page_count` is the ACTUAL extracted page count, never an
     * estimate, so page citations can never be guessed.
     *
     * A version is immutable once written; a new revision is a NEW row.
     */
    public function up(): void
    {
        Schema::create('knowledge_document_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('document_id');
            $table->unsignedInteger('version');
            $table->string('scope')->default('tenant');
            $table->string('scope_key')->default('');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            $table->string('language')->default('id');
            $table->string('mime')->default('application/pdf');
            $table->string('original_filename')->nullable();
            $table->string('storage_disk');
            $table->string('storage_path');
            // pending | extracted | failed
            $table->string('extraction_status')->default('pending');
            $table->text('extraction_error')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'version']);
            $table->unique(['scope', 'scope_key', 'sha256']);
            $table->index('sha256');

            $table->foreign('document_id')->references('id')->on('knowledge_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_versions');
    }
};
