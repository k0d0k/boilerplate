<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 — recorded patient consents (Modules/Patients).
     *
     * A consent is only ever "recorded" here after it was explicitly captured:
     * the row carries the consent TYPE, the document VERSION, the timestamp and
     * the ACTOR (a central user id, deliberately with NO cross-database foreign
     * key). Absence of a row means "no consent recorded" — the platform never
     * implies medical consent that was not explicitly recorded.
     */
    public function up(): void
    {
        Schema::create('patient_consents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->string('consent_type');
            $table->string('version');
            $table->string('status')->default('granted');
            $table->string('recorded_by');
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'consent_type']);
            $table->index('status');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
    }
};
