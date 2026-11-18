<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — append-only LBA examination events (Modules/LBA).
     *
     * Records every important workflow transition (and the actions that move an
     * examination between states) with the acting identity and a timestamp. The
     * table is append-only: rows are written by the workflow service and never
     * updated or deleted, so the examination lifecycle stays auditable.
     */
    public function up(): void
    {
        Schema::create('lba_examination_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('examination_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['examination_id', 'occurred_at']);

            $table->foreign('examination_id')->references('id')->on('lba_examinations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_examination_events');
    }
};
