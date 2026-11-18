<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS9.A — extracted page text (CENTRAL database).
     *
     * When a selectable-text PDF is ingested its text is extracted per ORIGINAL
     * PDF page and recorded here, one row per page, so downstream chunking can
     * always cite the true source page. A page with no extractable text keeps an
     * empty `content` (the version's `extraction_status` records why) — the
     * extractor never invents text for a scanned page.
     *
     * The extracted text is the raw document DATA, treated as untrusted quoted
     * context at answer time; it is never executed as instructions.
     */
    public function up(): void
    {
        Schema::create('knowledge_extracted_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('document_version_id');
            $table->unsignedInteger('page_number');
            $table->longText('content');
            $table->unsignedInteger('char_count')->default(0);
            $table->timestamps();

            $table->unique(['document_version_id', 'page_number']);

            $table->foreign('document_version_id')->references('id')->on('knowledge_document_versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_extracted_pages');
    }
};
