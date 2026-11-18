<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS4 P0 — distinguish an invited, credential-less account from an
     * established one.
     *
     * `must_set_password` is true ONLY for an account that was created by a
     * staff invitation and has never had a password set by its owner. It lets
     * the invitation flow decide, safely, between two paths:
     *
     *  - a brand-new account chooses its OWN password once (one-time setup);
     *  - an existing account must authenticate as itself to accept a
     *    membership, and its password is NEVER touched by an invitation token.
     *
     * Every pre-existing and every normally-created account defaults to false,
     * so established credentials (including Principal accounts) can never be
     * reset through an invitation.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_set_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_set_password');
        });
    }
};
