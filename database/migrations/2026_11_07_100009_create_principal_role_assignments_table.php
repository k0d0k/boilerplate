<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B-2A — principal / platform role assignment.
     *
     * Platform governance roles are not tenant-bound, so they are assigned
     * directly to an identity here rather than through a tenant membership.
     * `status` defaults to `pending` so a privileged assignment can never become
     * active implicitly; it must be explicitly activated (pending|active|revoked).
     */
    public function up(): void
    {
        Schema::create('principal_role_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('principal_role_assignments');
    }
};
