<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7B — configurable REPORT identity on the central brand catalog.
     *
     * A brand is commercial identity only (ARCH-05): brand != tenant != branch.
     * These columns describe how a brand appears on a patient-facing PDF report
     * header/footer. They are CENTRAL, non-clinical metadata — no patient data is
     * ever written here. The logo is an uploaded file whose PRIVATE disk + object
     * key are recorded here; the bytes live on a private disk and are only read
     * back through an authenticated, tenant-scoped endpoint.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('report_display_name')->nullable()->after('slug');
            $table->string('report_address')->nullable()->after('report_display_name');
            $table->string('report_contact')->nullable()->after('report_address');
            $table->string('report_website')->nullable()->after('report_contact');
            $table->string('report_language', 8)->default('id')->after('report_website');
            $table->text('report_header')->nullable()->after('report_language');
            $table->text('report_footer')->nullable()->after('report_header');
            $table->string('report_logo_disk')->nullable()->after('report_footer');
            $table->string('report_logo_path')->nullable()->after('report_logo_disk');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn([
                'report_display_name',
                'report_address',
                'report_contact',
                'report_website',
                'report_language',
                'report_header',
                'report_footer',
                'report_logo_disk',
                'report_logo_path',
            ]);
        });
    }
};
