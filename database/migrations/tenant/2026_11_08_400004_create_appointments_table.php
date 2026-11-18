<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 — tenant appointments (Modules/Appointments).
     *
     * Lives inside the tenant's isolated clinical database. `patient_id` and
     * `branch_id` are tenant-local foreign keys (patients / branches live in
     * the same database), while `staff_user_id` and `created_by` are central
     * user ids deliberately stored as plain strings with NO cross-database
     * foreign key — the central and tenant databases are separate connections
     * and must never be joined with a DB constraint.
     *
     * `ends_at` records the end of the slot (resolved from an explicit end time
     * or a duration at write time) so overlap detection is a simple range test.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->ulid('branch_id');
            $table->string('staff_user_id')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('service_type');
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
            $table->index(['staff_user_id', 'starts_at']);
            $table->index('status');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
