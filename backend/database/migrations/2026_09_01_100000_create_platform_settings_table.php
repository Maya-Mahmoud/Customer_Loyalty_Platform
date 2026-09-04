<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings that belong to the platform itself rather than to any one shop.
 *
 * Not in config/clp.php, because the supervisor changes these from a screen and a
 * config file is a deployment. Not on the merchants table either: a billing currency
 * that lived per merchant would let two shops be billed in different money for the
 * same plan, and the price is the platform's, not the shop's.
 *
 * A key/value table rather than a one-row table with a column per setting: the set
 * grows by a row instead of by a migration, and there is no risk of the single row
 * going missing and taking every default with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
