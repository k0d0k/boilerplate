<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7A — versioned LBA indicator catalog (CENTRAL database).
     *
     * A catalog version groups a set of indicator DEFINITIONS. It is central,
     * non-patient reference metadata: it holds NO clinical findings, NO patient
     * identity and NO examination data. Versioning means the catalog can be
     * added to, corrected, disabled or superseded later without ever changing a
     * historical assessment, which snapshots the version key and labels it was
     * assessed against.
     */
    public function up(): void
    {
        Schema::create('lba_indicator_catalog_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('status')->default('active');
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_indicator_catalog_versions');
    }
};
