<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS7.A — append-only practitioner audit trail.
     *
     * Records who acted on a practitioner (registration, profile change,
     * verification, suspension, reactivation, archival) and on that
     * practitioner's credentials, with a compact JSON snapshot of the change.
     * Deliberately small: it answers "who changed this, and when" without
     * copying sensitive credential data.
     */
    public function up(): void
    {
        Schema::create('practitioner_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('practitioner_id');
            $table->string('action');
            // Acting central user id: a plain string, no cross-database FK.
            $table->string('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['practitioner_id', 'occurred_at']);

            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_audit_logs');
    }
};
