<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — central payment gateway configuration (Modules/Commerce).
     *
     * Payment configuration is CENTRAL and platform-wide: it lives in the
     * central database and holds NO clinical data. Each provider
     * (midtrans / ipaymu / paypal) has exactly one row carrying its mode
     * (sandbox|live), enablement, non-secret `config`, `currencies` it supports
     * and an ENCRYPTED `credentials` payload.
     *
     * `credentials` is a plain TEXT column holding the encrypted ciphertext (an
     * `encrypted:array` cast); it is never a JSON column so the ciphertext can
     * never be interpreted as structured data at the database layer.
     * `verified_at` records when an operator confirmed the credentials work —
     * live charges are refused until it is set.
     */
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider')->unique();
            $table->string('label');
            $table->string('mode')->default('sandbox');
            $table->boolean('enabled')->default(false);
            $table->json('config')->nullable();
            $table->text('credentials')->nullable();
            $table->json('currencies')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
