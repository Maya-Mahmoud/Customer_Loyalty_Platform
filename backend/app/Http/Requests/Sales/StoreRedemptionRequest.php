<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paying a reward out (BRD 8.6).
 *
 * The customer comes from the route, and the branch and actor from the
 * authenticated account, so almost nothing is accepted from the client. What
 * remains is the exception path of BR-014: whether this is an override, and why.
 */
class StoreRedemptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'override' => ['boolean'],
            // Required whenever override is true. Enforced in RedemptionService so
            // the rule and its reasoning stay in one place.
            'override_reason' => ['nullable', 'string', 'min:5', 'max:1000'],
            // Only an owner sends this; a manager's branch comes from their account.
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
