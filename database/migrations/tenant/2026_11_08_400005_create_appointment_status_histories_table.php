<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 — minimal appointment status history (Modules/Appointments).
     *
     * Append-only log of every validated status transition, so an appointment's
     * lifecycle is auditable. `changed_by` is a central user id (no
     * cross-database foreign key).
     */
    public function up(): void
    {
        Schema::create('appointment_status_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('appointment_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('changed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index('appointment_id');

            $table->foreign('appointment_id')->references('id')->on('appointments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_status_histories');
    }
};
