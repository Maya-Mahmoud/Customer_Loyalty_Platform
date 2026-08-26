<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The store's editable details (BRD FR-MER-05, FR-MER-06).
 *
 * Deliberately not here: the registered name, the commercial register and the email.
 * Those identify the business and were verified at registration (BRD 8.1); letting
 * an owner rewrite them afterwards would make the verification decorative. The trade
 * name is the one customers see and is theirs to change.
 */
class UpdateStoreProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'trade_name' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[\d\s-]{8,32}$/'],
            /*
             * BRD FR-MER-05. Three letters, upper-cased, and no list of accepted
             * codes: a Syrian shop billing in a currency this file has never heard
             * of is their business, not a validation error.
             */
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper((string) $this->input('currency'))]);
        }
    }
}
