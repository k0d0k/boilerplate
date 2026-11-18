<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2B — tenant-scoped staff branch assignments.
     *
     * Lives in the tenant database, so an assignment can only ever reference a
     * branch of THAT tenant. `user_id` is the id of a central user but carries
     * NO cross-database foreign key: the central and tenant databases are
     * separate connections and must never be joined with a DB constraint. The
     * central membership / role of the user is validated in the application
     * layer before an assignment is written.
     *
     * Revocation is non-destructive: `status` becomes revoked and `revoked_at`
     * records when; assignments are never deleted.
     */
    public function up(): void
    {
        Schema::create('branch_staff_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('branch_id');
            // Central user id: a plain string, deliberately NOT a foreign key
            // (cross-database references are forbidden).
            $table->string('user_id');
            $table->string('status')->default('active');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
            $table->index('user_id');
            $table->index(['branch_id', 'status']);

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_staff_assignments');
    }
};
