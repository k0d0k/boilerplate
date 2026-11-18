<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 2 — tenant consultations (Modules/Consultations).
     *
     * A consultation is the clinical encounter record: the practitioner (a
     * central user id stored as a plain string, no cross-database foreign key),
     * the branch and patient (tenant-local foreign keys) and the encounter
     * lifecycle. `appointment_id` optionally links the encounter to the booking
     * it was held against. `channel` records how the encounter took place
     * (in person, SINA AI chat or a teleconsult).
     */
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->ulid('branch_id');
            $table->string('staff_user_id')->nullable();
            $table->ulid('appointment_id')->nullable();
            $table->string('channel')->default('sina_chat');
            $table->string('type')->default('consultation');
            $table->string('status')->default('scheduled');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('reason')->nullable();
            $table->text('summary')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'scheduled_at']);
            $table->index(['patient_id', 'scheduled_at']);
            $table->index(['staff_user_id', 'scheduled_at']);
            $table->index('status');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
