<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A — clinic-issued portal invitation proof (TENANT database).
     *
     * Binding a patient record to a central user account is an AUTHORIZED,
     * AUDITABLE clinic action that requires a verified invitation proof — never
     * an automatic match on an email or a phone number. An authorized clinic
     * identity issues a one-time secret for a specific patient row; the secret
     * is stored ONLY as a hash and expires, and the binding is refused unless the
     * presented secret matches the stored hash and has not expired.
     *
     * The columns live beside `user_id` on the patient row so the proof and the
     * result are destroyed together once the binding is consumed.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('portal_invite_token')->nullable()->after('user_id');
            $table->timestamp('portal_invite_expires_at')->nullable()->after('portal_invite_token');
            $table->string('portal_invite_issued_by')->nullable()->after('portal_invite_expires_at');
            $table->index('portal_invite_token');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['portal_invite_token']);
            $table->dropColumn([
                'portal_invite_token',
                'portal_invite_expires_at',
                'portal_invite_issued_by',
            ]);
        });
    }
};
