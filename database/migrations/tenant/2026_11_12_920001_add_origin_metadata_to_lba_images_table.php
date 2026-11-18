<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS6.C — per-image origin metadata (Modules/LBA).
     *
     * Records ENGINEERING provenance for each immutable original so operators
     * can see where a still came from, when it was captured and which
     * instrument produced it, alongside the existing SHA-256 `checksum`. These
     * columns are additive and nullable (or defaulted) so existing imaging rows
     * are untouched and no data is rewritten.
     *
     * The stored values are NON-CLINICAL: a source enum, a capture timestamp
     * and an instrument/device label. No patient or examination identity is
     * duplicated here, so this metadata is safe to surface in the workspace and
     * never leaks PHI into logs.
     */
    public function up(): void
    {
        Schema::table('lba_images', function (Blueprint $table) {
            $table->string('source')->default('manual_import');
            $table->timestamp('captured_at')->nullable();
            $table->string('instrument_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lba_images', function (Blueprint $table) {
            $table->dropColumn(['source', 'captured_at', 'instrument_label']);
        });
    }
};
