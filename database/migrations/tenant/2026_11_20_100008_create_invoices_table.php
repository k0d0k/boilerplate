<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS3 — tenant invoices (Modules/Commerce).
     *
     * One invoice per order records the amount that is owed and its settlement
     * state. An invoice is issued (never deleted): a cancelled order VOIDS its
     * invoice rather than removing it, so the financial trail stays intact.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('order_id');
            $table->string('number')->unique();
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('issued');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('status');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
