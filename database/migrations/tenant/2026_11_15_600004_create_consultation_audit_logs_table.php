<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 2 — minimal consultation audit trail (Modules/Consultations).
     *
     * Append-only: records the action, the acting central user id and a JSON
     * snapshot of the changed attributes. Deliberately small — it answers "who
     * acted on this consultation, and when" without storing any clinical
     * content beyond the action reference.
     */
    public function up(): void
    {
        Schema::create('consultation_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('consultation_id');
            $table->string('action');
            $table->string('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index('consultation_id');
            $table->index('action');

            $table->foreign('consultation_id')->references('id')->on('consultations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_audit_logs');
    }
};
