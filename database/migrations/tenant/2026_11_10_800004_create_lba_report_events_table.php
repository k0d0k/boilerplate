<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7B — append-only report audit trail (TENANT database).
     *
     * Every report action — generation, preview, authorized download — is
     * appended with the acting identity and timestamp. Rows are never updated or
     * deleted, so access to a patient-facing report stays auditable.
     */
    public function up(): void
    {
        Schema::create('lba_report_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_id')->constrained('lba_assessments')->cascadeOnDelete();
            $table->string('report_version_id')->nullable();
            $table->string('action');
            $table->string('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('assessment_id');
            $table->index('report_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_report_events');
    }
};
