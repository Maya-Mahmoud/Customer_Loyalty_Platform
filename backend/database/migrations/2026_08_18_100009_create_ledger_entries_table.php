<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core entity of the whole system (BRD 13.3).
 *
 * A customer's balance is never a stored number that gets overwritten; it is the
 * sum of these rows for the open cycle. That is what lets the merchant answer a
 * disputing customer, trace any discrepancy, and recompute retroactively when a
 * bug is found (BR-008).
 *
 * amount is signed: accruals are positive, reversals and cycle closes negative.
 * invoice_count_delta moves the same way, so a rule that counts invoices instead
 * of money is answered from the same ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Kept per entry so a branch-scoped rule (BRD FR-LOY-05) can filter
            // without joining back through the invoice.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('cycle_number');
            $table->string('type', 32);
            $table->decimal('amount', 12, 2)->default(0);
            $table->integer('invoice_count_delta')->default(0);

            // What caused the entry: an invoice, a redemption, a correction.
            $table->nullableMorphs('source');
            $table->foreignId('loyalty_rule_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'customer_id', 'cycle_number']);
            $table->index(['customer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
