<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers are recorded by a sales rep and never own an account (BRD BR-001).
 *
 * The unique key on (merchant_id, phone) is BR-002: the same phone number at
 * another merchant is a completely separate record with its own balance.
 *
 * There is no soft delete here. A privacy request (BRD FR-CUS-10) anonymises the
 * row instead, which keeps the ledger and the invoices intact while removing the
 * personal data — and keeps the unique key honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('name')->nullable();

            // Who captured the record and where. This is what makes the
            // collusion controls AF-03 and AF-11 possible.
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('registered_at_branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // BRD FR-CUS-07 and section 16: consent is captured verbally by the
            // rep and must stay withdrawable.
            $table->string('consent_status', 32)->default('not_collected');
            $table->timestamp('consent_recorded_at')->nullable();

            // BRD AF-07: optional one-time code check, off by default.
            $table->timestamp('phone_verified_at')->nullable();

            // Pointer to the open accumulation cycle. The balance itself is
            // never stored — it is summed from the ledger (BRD 13.3).
            $table->unsignedInteger('current_cycle_number')->default(1);
            $table->timestamp('last_purchase_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('anonymized_at')->nullable();

            $table->timestamps();

            $table->unique(['merchant_id', 'phone']);
            $table->index(['merchant_id', 'last_purchase_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
