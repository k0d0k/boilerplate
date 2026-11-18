<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 (WS1) — location scheduling on branches.
     *
     * Extends the Phase 2B `branches` table so ONE concept covers permanent
     * clinics and temporary / event locations without a parallel location
     * table (avoids duplicating the branch's address, staff and appointments):
     *
     *  - `location_type` distinguishes `permanent` from `temporary` / `event`;
     *  - `operates_from` / `operates_until` bound a temporary or event site's
     *    active window (null for a permanent branch);
     *  - `opens_at` / `closes_at` (`HH:MM`, in the branch timezone) describe the
     *    daily bookable window used to compute availability;
     *  - `slot_minutes` overrides the platform default slot length;
     *  - `capacity` caps how many OVERLAPPING occupying appointments the site
     *    accepts (null = unlimited — the historical behaviour).
     *
     * All columns are nullable/defaulted so existing rows keep working and the
     * branch capacity rule only engages once an operator sets a capacity.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('location_type')->default('permanent')->after('code');
            $table->timestamp('operates_from')->nullable()->after('email');
            $table->timestamp('operates_until')->nullable()->after('operates_from');
            $table->string('opens_at', 5)->nullable()->after('operates_until');
            $table->string('closes_at', 5)->nullable()->after('opens_at');
            $table->unsignedSmallInteger('slot_minutes')->nullable()->after('closes_at');
            $table->unsignedSmallInteger('capacity')->nullable()->after('slot_minutes');

            $table->index('location_type');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex(['location_type']);
            $table->dropColumn([
                'location_type',
                'operates_from',
                'operates_until',
                'opens_at',
                'closes_at',
                'slot_minutes',
                'capacity',
            ]);
        });
    }
};
