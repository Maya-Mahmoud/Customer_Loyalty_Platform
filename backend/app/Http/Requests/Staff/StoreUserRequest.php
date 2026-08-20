<?php

namespace App\Http\Requests\Staff;

use App\Enums\UserRole;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The staff form of BRD 8.2 step 2. No password field: the user chooses their own
 * through the invitation (BRD FR-BRN-04).
 */
class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Global, because the email is the sign-in identifier across the
            // whole platform.
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'min:8', 'max:20', 'regex:/^\+?\d+$/'],
            'role' => ['required', Rule::in(array_column(UserRole::assignableByOwner(), 'value'))],
            // Required for branch-bound roles; StaffService decides and reports.
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')
                    ->where('merchant_id', $this->user()->merchant_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'phone' => PhoneNumber::normalize($this->input('phone')),
        ]);
    }
}
