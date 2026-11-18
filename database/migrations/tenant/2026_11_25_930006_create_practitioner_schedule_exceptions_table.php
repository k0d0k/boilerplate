<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS7.B — tenant-local schedule exceptions of a practitioner.
     *
     * A dated override of the weekly schedule:
     *  - `leave`, `holiday`, `cancelled_session` SUBTRACT availability (the
     *    practitioner is not bookable in the range);
     *  - `extra_session` ADDS availability beyond the weekly windows (an ad-hoc
     *    clinic or an event session).
     *
     * `branch_id` is nullable: a null branch means the exception applies to
     * every branch the practitioner practises at (e.g. annual leave). Cancelling
     * an exception is non-destructive — only `status` changes.
     */
    public function up(): void
    {
        $tableName = 'practitioner_schedule_exceptions';

        // Repair-safe, exactly like practitioner_branch_authorizations: build
        // the columns once, then add each constraint idempotently so a retry
        // converges a partially-built table instead of failing with
        // "Table ... already exists" (error 1050).
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('practitioner_id');
                // null = applies to every branch of the practitioner.
                $table->ulid('branch_id')->nullable();
                $table->string('type');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->boolean('all_day')->default(false);
                $table->string('reason')->nullable();
                $table->string('status')->default('active');
                $table->string('created_by')->nullable();
                $table->timestamps();
            });
        }

        // Explicit SHORT names: the auto-generated
        // "practitioner_schedule_exceptions_practitioner_id_starts_at_ends_at_index"
        // is 72 characters and exceeds MySQL's 64-character identifier limit
        // (error 1059), the same failure mode as the authorization index.
        if (! Schema::hasIndex($tableName, ['practitioner_id', 'starts_at', 'ends_at'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['practitioner_id', 'starts_at', 'ends_at'], 'pse_practitioner_window_index');
            });
        }

        if (! Schema::hasIndex($tableName, ['practitioner_id', 'branch_id'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['practitioner_id', 'branch_id'], 'pse_practitioner_branch_index');
            });
        }

        if (! Schema::hasForeignKey($tableName, ['practitioner_id'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('practitioner_id', 'pse_practitioner_foreign')
                    ->references('id')->on('practitioners')->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey($tableName, ['branch_id'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('branch_id', 'pse_branch_foreign')
                    ->references('id')->on('branches')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_schedule_exceptions');
    }
};
