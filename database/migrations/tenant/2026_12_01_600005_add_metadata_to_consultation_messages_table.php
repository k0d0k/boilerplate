<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 9 (WS9.C) — structured response metadata for SINA messages.
     *
     * A knowledge-grounded assistant reply records its VALIDATED source
     * citations here (document id, title, version, the ORIGINAL PDF page and
     * the private authenticated download route), so the Patient panel and the
     * REST API render exactly the same provenance from one source of truth.
     *
     * The column is purely ADDITIVE and NULLABLE: every existing transcript row
     * stays valid, no clinical data moves, and a reply with no approved source
     * simply carries null (never a fabricated citation).
     */
    public function up(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('credits');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
