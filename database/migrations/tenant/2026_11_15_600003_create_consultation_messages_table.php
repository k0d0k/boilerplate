<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workstream 2 — SINA AI consultation chat transcript
     * (Modules/Consultations).
     *
     * One row per message in a consultation: the patient's prompt, the assistant
     * reply, a system note or a practitioner note. The transcript lives in the
     * tenant's isolated clinical database. Provider/model and integer token
     * counters are recorded alongside each assistant reply so usage is
     * auditable; the actual credit accounting lives in the central SINA wallet.
     */
    public function up(): void
    {
        Schema::create('consultation_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('consultation_id');
            // patient | assistant | system | practitioner
            $table->string('role');
            $table->text('content');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index('consultation_id');
            $table->index('role');

            $table->foreign('consultation_id')->references('id')->on('consultations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_messages');
    }
};
