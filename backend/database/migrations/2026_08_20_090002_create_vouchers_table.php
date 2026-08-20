<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase vouchers — spending credit issued as a reward and redeemed on a later
 * visit.
 *
 * Not in BRD 13: the document treats a voucher as one of three reward shapes
 * (FR-LOY-02) without saying what happens after it is handed over. Once it is
 * spent later it stops being a number and becomes an instrument with an owner, a
 * value, an expiry and exactly one permitted use — so it needs its own record.
 *
 * Every column after the value exists to answer "who accepted this, where, and
 * against which sale", because that is what a dispute or a fraud review asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // The redemption that produced it — the audit trail back to the cycle.
            $table->foreignId('redemption_id')->nullable()->constrained()->nullOnDelete();

            // What the customer reads out at the till.
            $table->string('code', 32);
            $table->decimal('amount', 12, 2);

            $table->string('status', 32)->default('issued');
            $table->timestamp('issued_at');
            // Null means it never expires.
            $table->timestamp('expires_at')->nullable();

            // Set together, on acceptance.
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_on_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('used_at_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            // Unique inside the merchant, following the same reasoning as BR-004
            // for invoice numbers: a code only ever means something in one store.
            $table->unique(['merchant_id', 'code']);
            $table->index(['merchant_id', 'customer_id']);
            $table->index(['merchant_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
