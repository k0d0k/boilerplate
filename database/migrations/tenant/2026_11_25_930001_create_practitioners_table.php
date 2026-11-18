<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS7.A — tenant practitioner registry (Modules/Practitioners).
     *
     * Lives inside the tenant's isolated database. `user_id` is the id of a
     * CENTRAL user (the practitioner's portal identity) stored as a plain
     * string with NO cross-database foreign key — the central and tenant
     * databases are separate connections and must never be joined with a DB
     * constraint. Being an active clinical member does NOT attest professional
     * qualifications; the `status` / `verified_*` columns record an explicit,
     * audited credential-verification decision (never automatic).
     *
     * The registry is non-destructive: `suspend()`/`archive()` only change
     * `status` (and stamp `suspended_at` / `archived_at`) so historical
     * appointments, consultations and LBA examinations stay intact.
     */
    public function up(): void
    {
        Schema::create('practitioners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Central user id: a plain string, deliberately NOT a foreign key.
            $table->string('user_id');
            $table->string('display_name');
            $table->string('title')->nullable();
            $table->string('professional_body')->nullable();
            $table->json('specialities')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioners');
    }
};
