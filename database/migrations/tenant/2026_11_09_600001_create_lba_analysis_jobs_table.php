<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 — asynchronous AI analysis jobs (Modules/LBA).
     *
     * One row per requested microscopy analysis. `request_ref` is OPAQUE: it is
     * the only reference ever sent to the external vision gateway, so no patient
     * identity, name, contact detail or clinical note can leave the tenant
     * database. `idempotency_key` is UNIQUE so re-requesting the same image with
     * the same provider/protocol can never enqueue a second job (and therefore
     * can never create a duplicate set of detections).
     *
     * The job is tenant-owned and lives in the isolated tenant database.
     */
    public function up(): void
    {
        Schema::create('lba_analysis_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('examination_id');
            $table->ulid('image_id');
            $table->string('request_ref');
            $table->string('status')->default('pending');
            $table->string('provider');
            $table->string('model_name')->nullable();
            $table->string('model_version')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('examination_id');
            $table->index('image_id');
            $table->index('status');

            $table->foreign('examination_id')->references('id')->on('lba_examinations')->cascadeOnDelete();
            $table->foreign('image_id')->references('id')->on('lba_images')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_analysis_jobs');
    }
};
