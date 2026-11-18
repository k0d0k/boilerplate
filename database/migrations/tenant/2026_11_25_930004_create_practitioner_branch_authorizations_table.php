<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS7.A — the branches a practitioner is authorized to practise at.
     *
     * Lives in the tenant's isolated database, so an authorization can only
     * ever reference a branch and a practitioner of THAT tenant. Both ids are
     * tenant-local (a real foreign key within the same isolated database).
     *
     * Revocation is non-destructive: `status` becomes revoked and `revoked_at`
     * records when; authorizations are never deleted, so the practitioner's
     * historical clinical footprint stays intact.
     */
    public function up(): void
    {
        $tableName = 'practitioner_branch_authorizations';

        // MySQL can leave the table behind if adding a later index fails. Create
        // the columns once, then add each constraint idempotently so a retry
        // repairs that partial state instead of failing with "table exists".
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('practitioner_id');
                $table->ulid('branch_id');
                $table->string('status')->default('active');
                $table->string('authorized_by')->nullable();
                $table->timestamp('authorized_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex($tableName, ['practitioner_id', 'branch_id'], 'unique')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unique(['practitioner_id', 'branch_id'], 'pract_branch_auth_pract_branch_unique');
            });
        }

        if (! Schema::hasIndex($tableName, ['branch_id'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index('branch_id', 'pract_branch_auth_branch_idx');
            });
        }

        if (! Schema::hasIndex($tableName, ['practitioner_id', 'status'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['practitioner_id', 'status'], 'pract_branch_auth_pract_status_idx');
            });
        }

        if (! Schema::hasForeignKey($tableName, ['practitioner_id'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('practitioner_id', 'pract_branch_auth_practitioner_fk')
                    ->references('id')->on('practitioners')->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey($tableName, ['branch_id'])) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('branch_id', 'pract_branch_auth_branch_fk')
                    ->references('id')->on('branches')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_branch_authorizations');
    }
};
