<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS9.B — the page-aware retrieval index (CENTRAL database).
     *
     * One row per approved chunk of a document VERSION's extracted page text.
     * `document_version_id` + `page_number` are the IMMUTABLE provenance of the
     * chunk, so a citation always resolves to a real source page and can never
     * be invented. `content_hash` makes reindexing idempotent, and
     * `embedding_version` pins the exact embedding model/version/dimensions so a
     * rebuild is reproducible.
     *
     * The chunk text is document DATA (untrusted quoted context at answer time),
     * held in the private central database and never logged with a patient
     * identifier. `embedding` is a nullable JSON vector; when no embedding
     * provider is configured the deterministic lexical index is used instead,
     * so retrieval never depends on an embedding service being present.
     */
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('document_id');
            $table->ulid('document_version_id');
            $table->ulid('collection_id');
            // platform | tenant
            $table->string('scope')->default('tenant');
            $table->string('scope_key')->default('');
            // The TRUE 1-based source page this chunk came from.
            $table->unsignedInteger('page_number');
            // The 0-based position of the chunk within the version.
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->unsignedInteger('char_count')->default(0);
            $table->unsignedInteger('token_estimate')->default(0);
            $table->string('content_hash', 64);
            // pending | ready | failed
            $table->string('embedding_status')->default('pending');
            $table->string('embedding_version')->nullable();
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->unique(['document_version_id', 'chunk_index']);
            $table->index(['document_version_id', 'page_number']);
            $table->index(['scope', 'scope_key', 'embedding_status']);
            $table->index('document_id');
            $table->index('content_hash');

            $table->foreign('document_id')->references('id')->on('knowledge_documents')->cascadeOnDelete();
            $table->foreign('document_version_id')->references('id')->on('knowledge_document_versions')->cascadeOnDelete();
            $table->foreign('collection_id')->references('id')->on('knowledge_collections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
