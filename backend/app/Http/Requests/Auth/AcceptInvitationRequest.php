<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * BRD FR-SEC-03 requires a strong password policy; Laravel's defaults plus a
 * compromised-password check give that without inventing our own rules.
 */
class AcceptInvitationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'confirmed',
                Password::min(6),
            ],
        ];
    }
}
