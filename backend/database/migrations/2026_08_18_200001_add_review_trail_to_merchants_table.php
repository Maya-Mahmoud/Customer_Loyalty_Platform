<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRD FR-MER-02 requires the owner's email and phone to be proven before the
 * account exists in any usable form, and BRD FR-ADM-02 requires the review
 * decision to be attributable. Both are recorded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');

            // Set once both codes are confirmed; this is the moment the request
            // enters the supervisor's queue (BRD 8.1 step 4).
            $table->timestamp('submitted_at')->nullable()->after('status_changed_at');
            $table->foreignId('reviewed_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['email_verified_at', 'phone_verified_at', 'submitted_at', 'reviewed_at']);
        });
    }
};
