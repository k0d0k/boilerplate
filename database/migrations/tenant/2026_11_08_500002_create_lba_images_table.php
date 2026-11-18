<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — private microscopy images (Modules/LBA).
     *
     * Each row is an IMMUTABLE reference to one original image held on a
     * private disk (`disk` + `object_key`). The object key is random and opaque
     * so storage layout can never be guessed; the SHA-256 `checksum` records
     * integrity. The storage path and `original_name` are never exposed by the
     * application — images are only read back through an authenticated,
     * tenant-scoped streaming endpoint. Originals are never written to
     * `public/`.
     */
    public function up(): void
    {
        Schema::create('lba_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('examination_id');
            $table->string('disk');
            $table->string('object_key');
            $table->string('original_name')->nullable();
            $table->string('checksum', 64);
            $table->string('mime_type');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('examination_id');
            $table->index('checksum');

            $table->foreign('examination_id')->references('id')->on('lba_examinations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_images');
    }
};
