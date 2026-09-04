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
             * Required, and unique inside the merchant (BR-004, AF-01).
             *
             * It carries a second job now: the customer proves a card is theirs by
             * quoting the number from their own receipt (FR-CUS-12), so a number the
             * system invented would prove nothing — only a number that was printed
             * and handed over can.
             */
            'invoice_number' => ['required', 'string', 'max:64'],
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
