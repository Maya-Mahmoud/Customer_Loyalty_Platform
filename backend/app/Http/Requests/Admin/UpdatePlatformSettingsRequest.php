<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Constrained to the same short list the shops trade in. A free field
             * here would let a typo become the label on every plan price on the
             * screen, and there is no exchange rate anywhere in this system to
             * notice that the money changed.
             */
            'billing_currency' => ['required', 'string', Rule::in(config('clp.currencies'))],
        ];
    }
}
