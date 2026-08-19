<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales recorded at the point of sale (BRD 8.4). The system stores invoices, it
 * does not issue them (BRD 5.2).
 *
 * customer_id is nullable on purpose: a customer who refuses to give a number
 * still gets their sale recorded, it simply accumulates nothing (BR-022).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            // The rep who keyed it in — never nulled, so BRD FR-BRN-05 holds even
            // after the user is disabled.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('invoice_number', 64);
            $table->decimal('amount', 12, 2);
            $table->date('invoice_date');

            $table->string('status', 32)->default('active');
            // False when the amount is under the rule's minimum (BR-003), so the
            // reason an invoice did not count stays visible after the fact.
            $table->boolean('qualifies_for_accumulation')->default(true);
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // BRD BR-004: unique inside one merchant, not across the platform.
            $table->unique(['merchant_id', 'invoice_number']);
            $table->index(['merchant_id', 'customer_id']);
            $table->index(['merchant_id', 'branch_id', 'invoice_date']);
            // Backs the AF-10 out-of-hours report, which looks at entry time.
            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
