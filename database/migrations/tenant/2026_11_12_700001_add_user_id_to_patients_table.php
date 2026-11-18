<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A — patient portal identity binding (TENANT database).
     *
     * Links a tenant-local patient record to the CENTRAL user account that
     * signs in to the patient panel. Like `staff_user_id` on appointments and
     * `operator_user_id` on examinations, the value is a central user id stored
     * as a plain string: there is no cross-database foreign key (the tenant
     * database and the central database are separate).
     *
     * The column is nullable on purpose — clinical patient rows exist before a
     * patient ever signs in, and most patients never create a portal account.
     * The patient portal resolves "my own patient record" ONLY through this
     * binding, so a patient can never reach another patient's data.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('user_id')->nullable();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
