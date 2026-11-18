<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS4 P0 — secure staff invitation / one-time account activation.
     *
     * A staff invitation never carries a usable credential. The invited
     * membership holds only the SHA-256 HASH of a single-use proof, together
     * with its expiry and the identity that issued it; the raw proof exists
     * exactly once, in the response handed back to the inviting Principal. The
     * invited identity redeems the proof to set its OWN password and activate
     * the membership, and `activated_at` records when that happened.
     *
     * The proof hash column is deliberately NOT mass-assignable on the model —
     * it is only ever written via an explicit `forceFill` in the invitation
     * service, mirroring the patient account-linker pattern.
     */
    public function up(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table) {
            $table->string('invite_token_hash', 64)->nullable()->after('status');
            $table->timestamp('invite_expires_at')->nullable()->after('invite_token_hash');
            $table->foreignId('invite_issued_by')->nullable()->after('invite_expires_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable()->after('invite_issued_by');

            $table->index('invite_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table) {
            $table->dropForeign(['invite_issued_by']);
            $table->dropIndex(['invite_token_hash']);
            $table->dropColumn([
                'invite_token_hash',
                'invite_expires_at',
                'invite_issued_by',
                'activated_at',
            ]);
        });
    }
};
