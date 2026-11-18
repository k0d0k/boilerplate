<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS4 — central subscription plan catalogue.
     *
     * A plan is a commercial licence template: what a tenant pays and which
     * modules it becomes licensed to use. It stores NO clinical data and is
     * never a tenant, brand or branch. Amounts are integer minor units with an
     * explicit ISO currency (never a float), matching the commerce conventions.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('status')->default('active');
            $table->decimal('price_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->string('billing_interval')->default('monthly');
            $table->unsignedInteger('trial_days')->default(0);
            $table->unsignedInteger('seat_limit')->nullable();
            $table->json('module_keys')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
