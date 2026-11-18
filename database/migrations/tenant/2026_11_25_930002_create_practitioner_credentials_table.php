<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS7.A — professional credentials / licences of a practitioner.
     *
     * Each row is an explicit credential with its own verification decision
     * (actor, timestamp, expiry, jurisdiction and type). `reference_number` is
     * the sensitive licence identifier: it is stored in the tenant's private
     * database, masked in every listing and NEVER written to logs. Optional
     * supporting evidence lives on a private disk (`evidence_disk` /
     * `evidence_path`); the platform serves it only through authorized,
     * audited streams and never via a public URL.
     *
     * Revocation/rejection/expiry are non-destructive — the row is retained and
     * only its status changes, so a practitioner's credential history survives.
     */
    public function up(): void
    {
        Schema::create('practitioner_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('practitioner_id');
            $table->string('credential_type');
            // Jurisdiction / issuing authority, e.g. an ISO-3166 country code.
            $table->string('jurisdiction')->nullable();
            // Sensitive licence identifier: masked in lists, never logged.
            $table->string('reference_number')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();
            $table->text('verification_notes')->nullable();
            $table->string('evidence_disk')->nullable();
            $table->string('evidence_path')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['practitioner_id', 'status']);
            $table->index('expires_at');

            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_credentials');
    }
};
