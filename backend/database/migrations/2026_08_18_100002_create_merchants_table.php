<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root. Every other business table hangs off a merchant_id, which is
 * what makes the isolation of BRD FR-ADM-06 enforceable in one place.
 *
 * Status-like columns are stored as strings and cast to PHP enums in the models:
 * adding a new case later is a code change, not an ALTER TABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();

            // Registration form of BRD 8.1
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('commercial_register')->unique();
            $table->string('owner_name');
            $table->string('email')->unique();
            $table->string('phone', 32);
            $table->string('city');

            // BRD FR-MER-05: settable per merchant, USD by default.
            $table->string('currency', 3)->default('USD');
            $table->string('logo_path')->nullable();

            $table->string('status', 32)->default('pending');
            // BRD FR-ADM-02 makes a reason mandatory on rejection and suspension.
            $table->text('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('activated_at')->nullable();

            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->date('subscription_ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
