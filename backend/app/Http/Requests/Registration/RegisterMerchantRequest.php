<?php

namespace App\Http\Requests\Registration;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * The self-registration form of BRD 8.1 step 1, plus the two agreements of
 * step 2. Uniqueness of the commercial register and of the email is decided in
 * MerchantRegistrationService, because a rejected applicant is allowed to reuse
 * their own record.
 */
class RegisterMerchantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'commercial_register' => ['required', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'min:8', 'max:20', 'regex:/^\+?\d+$/'],
            'city' => ['required', 'string', 'max:100'],
            // BRD FR-MER-05: the default is USD, and the owner may change it.
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],

            /*
             * Chosen here rather than through a link emailed after activation.
             * The mailbox is already proven by the code confirmed in the next
             * step, so a second round trip would prove nothing and only risks the
             * applicant never receiving it. BRD FR-SEC-03 sets the strength, and
             * FR-BRN-04 is still satisfied — the user picks their own password.
             */
            'password' => [
                'required',
                'confirmed',
                Password::min(10)->letters()->mixedCase()->numbers(),
            ],

            // BRD 8.1 step 2. Section 16 makes the data processing agreement the
            // legal basis for everything the platform later does with the data.
            'accepts_terms' => ['accepted'],
            'accepts_data_processing' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => PhoneNumber::normalize($this->input('phone')),
            'email' => strtolower(trim((string) $this->input('email'))),
            'currency' => strtoupper((string) $this->input('currency')) ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function merchantData(): array
    {
        return $this->safe()->except(['accepts_terms', 'accepts_data_processing']);
    }
}
