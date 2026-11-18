<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS7.B — tenant-local weekly practice schedule of a practitioner.
     *
     * One row is one recurring WEEKDAY window at ONE branch (a practitioner may
     * hold several windows, e.g. morning + afternoon, or different hours per
     * branch). Times are wall-clock times interpreted in the schedule's own
     * `timezone` (falling back to the branch timezone, then the app timezone),
     * so daylight-saving transitions are resolved by the calendar library rather
     * than by fixed offsets.
     *
     * `service_type` narrows a window to one service (or null for any).
     * `effective_from` / `effective_until` bound the recurrence to a date range.
     * Removal is non-destructive: only `status` changes, so the history of what
     * was once scheduled is retained.
     */
    public function up(): void
    {
        Schema::create('practitioner_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('practitioner_id');
            $table->ulid('branch_id');
            // ISO-8601 weekday: 1 = Monday … 7 = Sunday.
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            // Overrides the branch slot length when set.
            $table->unsignedSmallInteger('slot_minutes')->nullable();
            // null = the window applies to every service the practitioner offers.
            $table->string('service_type')->nullable();
            $table->string('timezone')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('status')->default('active');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['practitioner_id', 'branch_id', 'weekday']);
            $table->index(['practitioner_id', 'status']);

            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_schedules');
    }
};
