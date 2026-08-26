<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BRD FR-INV-01: invoice number, value, date, and the customer it belongs to.
 *
 * customer_id is nullable on purpose — a customer who will not give a number still
 * gets their sale recorded, it simply accumulates nothing (BR-022).
 *
 * The branch and the entering user are never accepted from the client; they come
 * from the authenticated account (FR-INV-03).
 */
class StoreInvoiceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Optional, an amendment to FR-INV-01. A shop with a till that prints
             * receipt numbers should still type one — it ties the entry to a sale
             * that demonstrably happened (AF-01) — but a shop writing receipts by
             * hand gained nothing from the field except typos, so the system numbers
             * the sale when it is left empty.
             */
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            // Not in the future: a sale cannot have happened yet. Backdating is
            // allowed, and is governed by the rule in force on that date.
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'customer_id' => ['nullable', 'integer'],
            // Required only for an owner, who belongs to no single branch.
            'branch_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['invoice_number' => trim((string) $this->input('invoice_number'))]);
    }
}
