<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionPlanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * The code is how everything else refers to the plan — seeders, reports,
             * and the staff talking about it. Unique because two plans answering to
             * "silver" would make every one of those references ambiguous, and
             * restricted to plain characters because it is an identifier and not a
             * label: the name is the field that gets to be Arabic and spaced.
             */
            'code' => ['required', 'string', 'max:40', 'alpha_dash', 'unique:subscription_plans,code'],
            'name' => ['required', 'string', 'max:120'],

            'monthly_price' => ['required', 'numeric', 'min:0', 'max:99999999'],

            // Nullable is the unlimited tier of BRD FR-ADM-04.
            'max_branches' => ['present', 'nullable', 'integer', 'min:1'],
            'max_users' => ['present', 'nullable', 'integer', 'min:1'],
            'max_monthly_invoices' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        /*
         * Lower-cased before the uniqueness check rather than after it, or "Silver"
         * and "silver" would both be accepted and then collide everywhere a code is
         * compared.
         */
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtolower(trim($this->input('code')))]);
        }
    }
}
