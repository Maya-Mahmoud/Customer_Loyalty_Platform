<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a balance by hand (BRD 7.2, ledger.adjust).
 *
 * The reason carries a floor for the same purpose it does on a correction request:
 * this is the entry an auditor stops at, and "تصحيح" explains nothing. Negative
 * amounts are allowed — a deduction is as legitimate a correction as an addition —
 * and the service checks it against what the customer actually has.
 */
class StoreAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-99999999.99', 'max:99999999.99'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            // Only an owner reaches this route, and an owner belongs to no branch.
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
