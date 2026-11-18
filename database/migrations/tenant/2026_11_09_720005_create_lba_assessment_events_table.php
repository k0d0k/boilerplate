<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7A — append-only assessment audit trail (TENANT database).
     *
     * Every meaningful assessment action — creation, an indicator change, a
     * status transition, an evidence link/unlink, a brand selection — is
     * appended with the acting identity and a timestamp. Rows are never updated
     * or deleted, so the assessment lifecycle stays auditable.
     */
    public function up(): void
    {
        Schema::create('lba_assessment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_id')->constrained('lba_assessments')->cascadeOnDelete();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('indicator_key')->nullable();
            $table->string('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index('assessment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_assessment_events');
    }
};
