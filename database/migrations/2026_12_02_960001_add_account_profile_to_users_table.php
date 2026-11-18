<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS9.E — account profile fields for every human identity.
     *
     * `phone` and `description` are self-service account attributes, editable
     * by the signed-in identity on its own account-profile page. They are
     * deliberately separate from any clinical/patient record: the account
     * profile is the LOGIN identity, not the patient chart.
     *
     * `pending_email` holds a requested new LOGIN email that is NOT yet in use:
     * the current `email` stays authoritative until the address is verified
     * through the signed link delivered to it, then `pending_email` is cleared
     * and `email` is switched. Keeping it separate means an unverified address
     * can never become the login email.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('email');
            $table->text('description')->nullable()->after('phone');
            $table->string('pending_email')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'description', 'pending_email']);
        });
    }
};
