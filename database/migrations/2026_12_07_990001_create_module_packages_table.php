<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 14 — trusted module package distribution.
     *
     * One row per uploaded package, recording ONLY provenance and review state:
     * the manifest identity, the SHA-256 checksum, the verified publisher, the
     * quarantine location and the outcome. No package code is ever executed and
     * the archive is never extracted into the application.
     */
    public function up(): void
    {
        Schema::create('module_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('module_key')->nullable();
            $table->string('version')->nullable();
            $table->string('publisher')->nullable();
            $table->string('state');
            $table->string('checksum', 64);
            $table->text('signature')->nullable();
            $table->string('archive_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('reason')->nullable();
            $table->string('activated_tenant_id')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['module_key', 'state']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_packages');
    }
};
