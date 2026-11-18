<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 9 (WS9.C) — per-turn SINA idempotency ledger.
     *
     * One row per (consultation, turn_reference) pair. `turn_reference` is an
     * OPTIONAL client-supplied idempotency key: when a client retries a dropped
     * request with the SAME reference, the reply already stored for the turn is
     * replayed instead of prompting the provider again, appending a second
     * transcript row or moving a credit again.
     *
     * The recorded `content_hash` lets the service REJECT a reference that is
     * reused with DIFFERENT content (a genuine client bug, not a retry), and the
     * `attempts` / `provider_calls` counters make the retry auditable without
     * storing any clinical content beyond the transcript it already references.
     *
     * Purely additive: it references the existing consultation, never stores
     * message bodies, and leaves every existing transcript row untouched.
     */
    public function up(): void
    {
        Schema::create('consultation_sina_turns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('consultation_id');
            $table->string('turn_reference');
            $table->string('content_hash');
            // pending | succeeded | failed
            $table->string('status')->default('pending');
            $table->ulid('patient_message_id')->nullable();
            $table->ulid('assistant_message_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('provider_calls')->default(0);
            $table->string('error_code')->nullable();
            $table->timestamps();

            // One idempotency key per consultation: a replayed reference can
            // never create a second ledger row.
            $table->unique(['consultation_id', 'turn_reference']);
            $table->index('consultation_id');

            $table->foreign('consultation_id')->references('id')->on('consultations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_sina_turns');
    }
};
