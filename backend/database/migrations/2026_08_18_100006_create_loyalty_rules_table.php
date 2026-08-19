<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tunable rule engine of BRD 11. Editing a rule never rewrites history
 * (BR-015): the current row gets an effective_to, and a new version is inserted.
 * That is why the rule is versioned rather than updated in place (FR-LOY-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);

            $table->string('threshold_type', 32)->default('amount');
            $table->decimal('threshold_amount', 12, 2)->nullable();
            $table->unsignedInteger('threshold_invoice_count')->nullable();

            $table->string('reward_type', 32)->default('percentage');
            $table->decimal('reward_value', 12, 2);
            // BRD BR-021: a percentage reward never exceeds this absolute cap.
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            // BRD BR-003: invoices below this are recorded but not accumulated.
            $table->decimal('min_invoice_amount', 12, 2)->default(0);

            $table->string('accumulation_scope', 32)->default('merchant');
            $table->string('reset_policy', 32)->default('carry_over');
            // BRD BR-017: null means the balance never expires.
            $table->unsignedInteger('balance_validity_months')->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['merchant_id', 'version']);
            $table->index(['merchant_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rules');
    }
};
