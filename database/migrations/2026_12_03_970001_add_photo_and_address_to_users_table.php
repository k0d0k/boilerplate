<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Account profile — private photo and postal address.
     *
     * `photo_path` stores the object key of the account's own photo on the
     * PRIVATE `account-photos` disk (never a public URL): the file is only ever
     * read back through the authenticated streaming endpoint. `address` is the
     * account holder's own postal address, editable only on their own profile.
     *
     * Both columns are additive and nullable so existing accounts are unaffected.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('description');
            $table->string('address', 255)->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'address']);
        });
    }
};
