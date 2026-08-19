<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns Laravel's default users table into the staff table of BRD 7.
 *
 * merchant_id and branch_id are nullable because the platform supervisor is the
 * one role that belongs to no merchant and no branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->after('merchant_id')->constrained()->nullOnDelete();
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('role', 32)->default('sales_rep')->after('phone');
            $table->string('status', 32)->default('invited')->after('role');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->softDeletes();

            $table->index(['merchant_id', 'role']);
            // Backs the AF-04 control: a staff phone may never be registered as
            // a customer, so it has to be cheap to look up.
            $table->index(['merchant_id', 'phone']);
        });

        // The default column is not nullable, but an invited user has no password
        // until they follow the invitation link (BRD FR-BRN-04).
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'phone']);
            $table->dropIndex(['merchant_id', 'role']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('merchant_id');
            $table->dropColumn(['phone', 'role', 'status', 'last_login_at', 'deleted_at']);
        });
    }
};
