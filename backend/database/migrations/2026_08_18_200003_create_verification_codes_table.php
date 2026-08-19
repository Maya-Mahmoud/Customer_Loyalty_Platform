<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes sent to prove ownership of an email address or a phone number.
 *
 * Used by merchant registration now (BRD FR-MER-02), and by the optional customer
 * check of AF-07 and the self-service lookup of FR-CUS-12 later — the mechanism is
 * identical, only the purpose differs.
 *
 * Codes are hashed, attempts are counted, and rows are consumed rather than
 * deleted so a replay is visible (BRD AF-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 64);
            $table->string('channel', 16);
            // The email address or phone number the code was sent to.
            $table->string('destination');
            $table->string('code_hash', 64);

            $table->nullableMorphs('verifiable');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['purpose', 'destination']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
