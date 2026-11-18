<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS9.A — append-only knowledge document audit (CENTRAL database).
     *
     * Answers "who uploaded / verified / approved / published / archived /
     * revoked this document, and when" without storing any document body beyond
     * the recorded action. No patient identifier is ever written here.
     */
    public function up(): void
    {
        Schema::create('knowledge_document_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('document_id');
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'action']);

            $table->foreign('document_id')->references('id')->on('knowledge_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_events');
    }
};
