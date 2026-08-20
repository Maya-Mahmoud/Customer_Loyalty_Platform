<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A purchase voucher is spent on a later visit, so it needs a life of its own.
 *
 * BRD FR-LOY-07 gives balances an inactivity window but says nothing about
 * vouchers, and leaving them open-ended would keep an unbounded financial
 * commitment on the merchant's books — the very thing BR-017 avoids for balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rules', function (Blueprint $table) {
            // Null means the voucher never expires, which the owner may choose.
            $table->unsignedInteger('voucher_validity_days')->nullable()->after('balance_validity_months');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rules', function (Blueprint $table) {
            $table->dropColumn('voucher_validity_days');
        });
    }
};
