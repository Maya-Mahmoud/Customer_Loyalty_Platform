<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            // Names the issued Sanctum token so a user can tell their sessions
            // apart, and so revoking one device does not sign out the rest.
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function deviceName(): string
    {
        return $this->input('device_name') ?: 'web';
    }
}
