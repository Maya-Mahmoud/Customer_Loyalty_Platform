<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The request-and-approve trail behind BRD 8.7. A sales rep can never amend an
 * invoice directly (BR-012); they raise a request with a reason and a branch
 * manager or the owner decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);
            // Only set for a partial return (BRD FR-INV-07).
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('reason');

            $table->string('status', 32)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_corrections');
    }
};
