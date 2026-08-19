<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each discount actually handed to a customer (BRD 8.6).
 *
 * Both the computed and the capped amount are stored: when a customer asks why
 * they received 50 and not 116, the answer is in the row, not in a recalculation
 * that may since have changed (BRD 11.2, BR-021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('loyalty_rule_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('cycle_number');
            // Snapshot of the cycle at the moment of redemption.
            $table->decimal('cycle_total_amount', 12, 2)->default(0);
            $table->unsignedInteger('cycle_invoice_count')->default(0);

            $table->string('reward_type', 32);
            $table->decimal('computed_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('carried_over_amount', 12, 2)->default(0);

            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();

            // BRD BR-014 and BR-018: paying out early, or twice in a day, needs
            // the owner's approval and a written reason.
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('override_approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->index(['merchant_id', 'customer_id']);
            $table->index(['merchant_id', 'redeemed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};
