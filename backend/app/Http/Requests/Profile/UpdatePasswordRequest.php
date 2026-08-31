<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Changing your own password (BRD FR-SEC-01).
 *
 * current_password is Laravel's rule for "prove you are the one asking", checked
 * against the signed-in user's hash. Without it an unlocked till left on the counter
 * would be enough to take an account over.
 */
class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            // Same strength as the rest of the system: one rule, one place.
            'password' => ['required', 'confirmed', Password::min(6)],
        ];
    }
}
