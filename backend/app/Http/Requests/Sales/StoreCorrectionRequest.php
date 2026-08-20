<?php

namespace App\Http\Requests\Sales;

use App\Enums\CorrectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking for an invoice to be cancelled or returned (BRD 8.7).
 *
 * The reason is required and has a floor, because BRD FR-INV-06 makes it the whole
 * basis of the manager's decision — "خطأ" tells the person approving nothing, and it
 * is what an auditor reads months later.
 */
class StoreCorrectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CorrectionType::class)],
            // Only a partial return carries one (BRD FR-INV-07); the service checks
            // it against the invoice.
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
