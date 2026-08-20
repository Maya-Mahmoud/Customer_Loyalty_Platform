<?php

namespace App\Http\Requests\Staff;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The branch form of BRD 8.2 step 1.
 */
class StoreBranchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Unique inside the merchant only: two stores may both have a
            // "Damascus Branch" without colliding.
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('branches', 'name')
                    ->where('merchant_id', $this->user()->merchant_id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('branch')?->getKey()),
            ],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'min:6', 'max:20', 'regex:/^\+?\d+$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
    }
}
