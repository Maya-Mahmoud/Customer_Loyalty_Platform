<?php

namespace App\Services\Loyalty;

use App\Enums\RewardType;
use App\Enums\ThresholdType;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the lifecycle of a merchant's loyalty rule (BRD 8.3, FR-LOY-08).
 *
 * A rule is never edited in place. Changing it closes the current version and
 * inserts the next one, which is what makes BR-015 true by construction rather
 * than by discipline: a customer who has reached 900 of a 1,000 threshold keeps
 * that threshold, because their invoices are still governed by the version that
 * was in force when they were recorded.
 */
class LoyaltyRuleService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Every version, newest first — the audit trail BRD FR-LOY-08 asks for.
     *
     * @return Collection<int, LoyaltyRule>
     */
    public function history(Merchant $merchant): Collection
    {
        return LoyaltyRule::query()
            ->with('createdBy')
            ->orderByDesc('version')
            ->get();
    }

    public function current(Merchant $merchant): ?LoyaltyRule
    {
        return $merchant->ruleEffectiveOn();
    }

    /**
     * Publishes a new version.
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(Merchant $merchant, array $data, User $actor): LoyaltyRule
    {
        $effectiveFrom = $data['effective_from'] ?? now()->toDateString();

        $this->guardConsistency($data);
        $this->guardEffectiveDate($merchant, $effectiveFrom);

        return DB::transaction(function () use ($merchant, $data, $actor, $effectiveFrom): LoyaltyRule {
            $previous = LoyaltyRule::orderByDesc('version')->first();

            /*
             * The outgoing version stops the day before the new one starts, so the
             * two never both apply to a single date. Without that, ruleEffectiveOn
             * would have to guess.
             */
            if ($previous !== null) {
                $previous->forceFill([
                    'effective_to' => now()->parse($effectiveFrom)->subDay()->toDateString(),
                    'is_active' => false,
                ])->save();
            }

            $rule = LoyaltyRule::create([
                ...$data,
                'version' => ($previous?->version ?? 0) + 1,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'is_active' => true,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                action: $previous === null ? 'loyalty_rule.created' : 'loyalty_rule.superseded',
                entity: $rule,
                before: $previous?->only([
                    'version', 'threshold_type', 'threshold_amount', 'threshold_invoice_count',
                    'reward_type', 'reward_value', 'max_discount_amount', 'min_invoice_amount',
                    'accumulation_scope', 'reset_policy', 'balance_validity_months',
                ]),
                after: $rule->only([
                    'version', 'threshold_type', 'threshold_amount', 'threshold_invoice_count',
                    'reward_type', 'reward_value', 'max_discount_amount', 'min_invoice_amount',
                    'accumulation_scope', 'reset_policy', 'balance_validity_months',
                    'effective_from',
                ]),
                actor: $actor,
            );

            return $rule;
        });
    }

    /**
     * Gives a brand new merchant a working rule from the defaults of BRD 11.1, so
     * the first sale can be recorded before anyone has visited the settings.
     */
    public function seedDefaults(Merchant $merchant, User $actor): LoyaltyRule
    {
        return $this->publish($merchant, LoyaltyRule::defaults(), $actor);
    }

    /**
     * The threshold and reward fields that actually matter depend on the types
     * chosen, so the combination is checked rather than each field alone.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardConsistency(array $data): void
    {
        $thresholdType = $data['threshold_type'] instanceof ThresholdType
            ? $data['threshold_type']
            : ThresholdType::from($data['threshold_type']);

        $rewardType = $data['reward_type'] instanceof RewardType
            ? $data['reward_type']
            : RewardType::from($data['reward_type']);

        $errors = [];

        if ($thresholdType->tracksAmount() && ! ($data['threshold_amount'] ?? null) > 0) {
            $errors['threshold_amount'] = __('Set the amount the customer has to reach.');
        }

        if ($thresholdType->tracksInvoiceCount() && ! ($data['threshold_invoice_count'] ?? null) > 0) {
            $errors['threshold_invoice_count'] = __('Set the number of invoices the customer has to reach.');
        }

        if ($rewardType === RewardType::Percentage) {
            $value = (float) ($data['reward_value'] ?? 0);

            if ($value <= 0 || $value > 100) {
                $errors['reward_value'] = __('A percentage reward must be between 1 and 100.');
            }

            // BRD BR-021 exists to bound the merchant's exposure on a large cycle;
            // a percentage with no ceiling has none.
            if (! ($data['max_discount_amount'] ?? null) > 0) {
                $errors['max_discount_amount'] = __('A percentage reward needs a maximum discount.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A version may start today or later, never in the past: backdating one would
     * rewrite the rule that already governed recorded invoices, which is exactly
     * what BR-015 forbids.
     */
    private function guardEffectiveDate(Merchant $merchant, string $effectiveFrom): void
    {
        if (now()->parse($effectiveFrom)->startOfDay()->isBefore(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'effective_from' => __('A rule cannot start in the past. Existing balances are governed by the version that was in force.'),
            ]);
        }

        $latest = LoyaltyRule::orderByDesc('version')->first();

        if ($latest !== null && now()->parse($effectiveFrom)->lte(now()->parse($latest->effective_from))) {
            throw ValidationException::withMessages([
                'effective_from' => __('The start date must be later than the current version, which starts on :date.', [
                    'date' => now()->parse($latest->effective_from)->toDateString(),
                ]),
            ]);
        }
    }
}
