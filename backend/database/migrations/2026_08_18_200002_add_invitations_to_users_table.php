<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation links let a user set their own password instead of being sent one
 * (BRD FR-BRN-04). The owner of a newly activated merchant is invited the same
 * way, so no password ever travels by email.
 *
 * Only the hash of the token is stored: a leaked database does not hand out
 * working invitation links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('invitation_token', 64)->nullable()->unique()->after('status');
            $table->timestamp('invitation_sent_at')->nullable()->after('invitation_token');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['invitation_token']);
            $table->dropColumn(['invitation_token', 'invitation_sent_at', 'invitation_expires_at']);
        });
    }
};
