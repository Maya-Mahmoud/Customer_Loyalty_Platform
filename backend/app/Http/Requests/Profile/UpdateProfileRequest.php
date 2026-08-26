<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The caller's own name and phone.
 *
 * No email and no role: the first identifies the account, the second is the store
 * owner's to hand out (BRD 8.2). A profile screen that could raise your own role
 * would make the whole matrix of BRD 7.2 advisory.
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[\d\s-]{8,20}$/'],
        ];
    }
}
