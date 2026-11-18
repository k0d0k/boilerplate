<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — tenant LBA examinations (Modules/LBA).
     *
     * Lives inside the tenant's isolated clinical database. `patient_id`,
     * `branch_id`, `appointment_id` and `consent_id` are tenant-local foreign
     * keys (patients / branches / appointments / patient_consents all live in
     * the same database), while `operator_user_id` and `created_by` are central
     * user ids stored as plain strings with NO cross-database foreign key.
     *
     * `mode` is manual | ai | hybrid; only `manual` is processed in Phase 4.
     * `status` is the workflow state machine (draft → uploaded →
     * manual_annotation → review_required, plus returned_for_changes and
     * cancelled). `calibration` is an optional, opaque JSON blob.
     */
    public function up(): void
    {
        Schema::create('lba_examinations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->ulid('branch_id');
            $table->ulid('appointment_id')->nullable();
            $table->string('operator_user_id')->nullable();
            $table->string('mode')->default('manual');
            $table->string('status')->default('draft');
            $table->timestamp('captured_at')->nullable();
            $table->string('instrument')->nullable();
            $table->string('magnification')->nullable();
            $table->json('calibration')->nullable();
            $table->text('notes')->nullable();
            $table->ulid('consent_id')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['patient_id', 'captured_at']);
            $table->index('status');
            $table->index('mode');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
            $table->foreign('consent_id')->references('id')->on('patient_consents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_examinations');
    }
};
