<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 2 — minimal consultation status history
     * (Modules/Consultations).
     *
     * Append-only log of every validated status transition, so a consultation's
     * lifecycle is auditable. `changed_by` is a central user id (no
     * cross-database foreign key).
     */
    public function up(): void
    {
        Schema::create('consultation_status_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('consultation_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('changed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index('consultation_id');

            $table->foreign('consultation_id')->references('id')->on('consultations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_status_histories');
    }
};
