<?php

namespace App\Http\Requests\Staff;

use App\Enums\UserRole;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a staff account, including the branch move of BRD FR-BRN-06.
 *
 * The email is not editable: it identifies the account and has already been used
 * to send an invitation. Changing it belongs to a verified flow of its own.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'min:8', 'max:20', 'regex:/^\+?\d+$/'],
            'role' => ['sometimes', 'required', Rule::in(array_column(UserRole::assignableByOwner(), 'value'))],
            'branch_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('branches', 'id')
                    ->where('merchant_id', $this->user()->merchant_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }
}
