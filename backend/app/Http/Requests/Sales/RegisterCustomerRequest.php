<?php

namespace App\Http\Requests\Sales;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BRD FR-CUS-01: the rep records the customer themselves — name and mobile only,
 * with nothing required from the customer.
 *
 * Section 16 limits collection to that minimum deliberately, so there is no room
 * here for an address, a birthday or anything else nobody needs.
 */
class RegisterCustomerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:8', 'max:20', 'regex:/^\+?\d+$/'],
            'name' => ['required', 'string', 'max:255'],
            // BRD FR-CUS-07: the spoken agreement to receive messages, recorded by
            // the rep because the customer has no account to tick a box in.
            'consent_given' => ['boolean'],
            // Only an owner sends this; a rep's branch comes from their account.
            'branch_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
    }
}
