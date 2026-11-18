<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS9.A — knowledge documents (CENTRAL database).
     *
     * The logical, versioned reference document: bibliographic metadata (title,
     * author, publication, date, license, source URL, language) plus the review
     * lifecycle. The binary itself lives on the private disk
     * ({@see knowledge_document_versions}); only metadata and the approval state
     * are recorded here.
     *
     * Lifecycle: uploaded -> processing -> review -> published, plus the
     * terminal/administrative states archived and revoked. A document is NEVER
     * retrievable until an authorised human publishes it.
     */
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('collection_id');
            // platform | tenant
            $table->string('scope')->default('tenant');
            $table->string('scope_key')->default('');
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('publication')->nullable();
            $table->date('published_on')->nullable();
            $table->string('license')->nullable();
            $table->string('source_url')->nullable();
            // id | en | ms-MY
            $table->string('language')->default('id');
            $table->unsignedInteger('current_version')->default(1);
            // uploaded | processing | review | published | archived | revoked
            $table->string('status')->default('uploaded');
            $table->string('uploaded_by')->nullable();
            $table->string('verified_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('collection_id');
            $table->index(['scope', 'scope_key', 'status']);
            $table->index(['scope', 'scope_key', 'language']);

            $table->foreign('collection_id')->references('id')->on('knowledge_collections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
