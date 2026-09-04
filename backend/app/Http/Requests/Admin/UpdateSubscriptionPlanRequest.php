<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionPlanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * A price, not a discount: zero is allowed because a free tier is a real
             * commercial decision, and the ceiling only exists because the column is
             * decimal(10,2) and a number that will not fit should be refused here
             * with a message rather than by the database with an exception.
             */
            'monthly_price' => ['required', 'numeric', 'min:0', 'max:99999999'],

            /*
             * The caps of BRD FR-ADM-04. Nullable means unlimited, which is why they
             * are 'present' rather than 'required' — an absent key would otherwise be
             * indistinguishable from a deliberate "no limit" and the plan would keep
             * a cap the supervisor thought they had removed.
             */
            'max_branches' => ['present', 'nullable', 'integer', 'min:1'],
            'max_users' => ['present', 'nullable', 'integer', 'min:1'],
            'max_monthly_invoices' => ['present', 'nullable', 'integer', 'min:1'],

            /*
             * Retiring a plan rather than deleting it. Shops are attached to it by a
             * foreign key and their subscription history refers to it, so the only
             * safe way to stop selling a plan is to stop offering it: it disappears
             * from registration and stays visible, and correctable, in this console.
             *
             * Optional, so a save that only changes a price does not have to restate
             * whether the plan is on sale.
             */
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
