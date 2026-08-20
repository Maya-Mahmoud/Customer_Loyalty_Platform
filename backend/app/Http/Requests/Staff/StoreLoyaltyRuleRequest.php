<?php

namespace App\Http\Requests\Staff;

use App\Enums\AccumulationScope;
use App\Enums\ResetPolicy;
use App\Enums\RewardType;
use App\Enums\ThresholdType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The ten tunable parameters of BRD 11.1.
 *
 * Shape only. Which fields actually matter depends on the types chosen — a
 * percentage needs a ceiling, an amount threshold needs an amount — and those
 * combinations are decided in LoyaltyRuleService, so the rule and its reasons stay
 * in one place.
 */
class StoreLoyaltyRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'threshold_type' => ['required', Rule::enum(ThresholdType::class)],
            'threshold_amount' => ['nullable', 'numeric', 'min:1', 'max:99999999'],
            'threshold_invoice_count' => ['nullable', 'integer', 'min:1', 'max:1000'],

            'reward_type' => ['required', Rule::enum(RewardType::class)],
            'reward_value' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'min_invoice_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],

            'accumulation_scope' => ['required', Rule::enum(AccumulationScope::class)],
            'reset_policy' => ['required', Rule::enum(ResetPolicy::class)],
            // Null means the balance never expires (BRD FR-LOY-07).
            'balance_validity_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            // Only meaningful for a voucher reward; null means it never expires.
            'voucher_validity_days' => ['nullable', 'integer', 'min:1', 'max:1825'],

            // Today or later. Backdating would rewrite the rule that already
            // governed recorded invoices, which BR-015 forbids.
            'effective_from' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
