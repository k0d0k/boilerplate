<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'lba_images_examination_checksum_unique';

    /**
     * WS6.1 — race-safe duplicate intake (Modules/LBA).
     *
     * Two uploads of IDENTICAL content to the SAME examination must resolve to
     * ONE immutable original. The application serialises concurrent uploads
     * with a row lock on the examination; this ADDITIVE unique index is the hard
     * cross-process guarantee behind it, so a lost race can never persist a
     * second immutable record.
     *
     * This migration is deliberately NON-DESTRUCTIVE: it adds an index and
     * touches no existing row. If a tenant database already contains legacy
     * duplicate (examination_id, checksum) rows — possible because earlier
     * releases had no such constraint — the index is NOT created and nothing is
     * deleted; a loud warning is logged for operator review. Silently deleting
     * historical clinical images is never acceptable, and a blocked tenant
     * migration is worse than a missing index, because the application-level row
     * lock + pre-insert duplicate check still prevent NEW duplicates.
     */
    public function up(): void
    {
        if (! Schema::hasTable('lba_images')) {
            return;
        }

        $duplicates = DB::table('lba_images')
            ->select('examination_id', 'checksum')
            ->groupBy('examination_id', 'checksum')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            Log::warning('LBA: skipped unique (examination_id, checksum) index — legacy duplicate originals detected.', [
                'duplicate_groups' => $duplicates->count(),
                'examinations' => $duplicates->pluck('examination_id')->unique()->values()->all(),
            ]);

            return;
        }

        Schema::table('lba_images', function (Blueprint $table) {
            $table->unique(['examination_id', 'checksum'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lba_images')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('lba_images'))->pluck('name');

        if ($indexes->contains(self::INDEX)) {
            Schema::table('lba_images', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }
    }
};
