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
    /** The columns an audit entry records, named once so the two save paths agree. */
    private const AUDITED = [
        'version', 'threshold_type', 'threshold_amount', 'threshold_invoice_count',
        'reward_type', 'reward_value', 'max_discount_amount', 'min_invoice_amount',
        'accumulation_scope', 'reset_policy', 'balance_validity_months',
        'effective_from',
    ];

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
     * Saves the rule: a correction to the version that owns the day, or the next
     * version if the one in force belongs to an earlier day.
     *
     * The distinction is what BR-015 rests on. A version whose start date has
     * already passed has governed invoices, and those invoices must keep being
     * explainable — so changing the rule inserts a new version and leaves the old
     * one intact. A version that starts today or later has governed nothing on any
     * earlier date, so an owner adjusting the figures is correcting a draft, and
     * stacking a version per keystroke would fill the history with rules that never
     * priced a single sale.
     *
     * The invariant either way: one version owns a date. ruleEffectiveOn() would
     * have to guess between two versions sharing a start, and the correction that
     * happened is not lost — it is in the audit trail, which is append-only.
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(Merchant $merchant, array $data, User $actor): LoyaltyRule
    {
        $effectiveFrom = $data['effective_from'] ?? now()->toDateString();

        $this->guardConsistency($data);
        $this->guardEffectiveDate($merchant, $effectiveFrom);

        $replaceable = $this->versionOwning($effectiveFrom);

        if ($replaceable !== null) {
            return $this->correct($replaceable, $data, $effectiveFrom, $actor);
        }

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
                before: $previous?->only(self::AUDITED),
                after: $rule->only(self::AUDITED),
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

        /*
         * Strictly before, where it used to be before-or-equal.
         *
         * The equal case is now a correction to the version that owns that day
         * (see versionOwning), which is what lets an owner press save twice in one
         * morning. Earlier than the current version stays refused: it would insert a
         * version starting before one that is already scheduled, and closing the
         * outgoing version "the day before the new one" would then run backwards.
         */
        if ($latest !== null && now()->parse($effectiveFrom)->startOfDay()->lt(now()->parse($latest->effective_from)->startOfDay())) {
            throw ValidationException::withMessages([
                'effective_from' => __('The start date must be later than the current version, which starts on :date.', [
                    'date' => now()->parse($latest->effective_from)->toDateString(),
                ]),
            ]);
        }
    }

    /**
     * The newest version, when the incoming save is a correction to it.
     *
     * Two conditions, and both are needed. The dates must be the *same* day, because
     * a save aimed at a later day is a deliberate second rule — the current version
     * will have governed the days in between, and those days have to keep their
     * rule. And that day must not be in the past, because a version whose start has
     * passed has already priced invoices.
     */
    private function versionOwning(string $effectiveFrom): ?LoyaltyRule
    {
        $latest = LoyaltyRule::orderByDesc('version')->first();

        if ($latest === null) {
            return null;
        }

        $starts = now()->parse($latest->effective_from)->startOfDay();
        $incoming = now()->parse($effectiveFrom)->startOfDay();

        return $starts->equalTo($incoming) && $starts->gte(now()->startOfDay())
            ? $latest
            : null;
    }

    /**
     * Rewrites a version that has not governed an earlier day, keeping its number.
     *
     * @param  array<string, mixed>  $data
     */
    private function correct(
        LoyaltyRule $rule,
        array $data,
        string $effectiveFrom,
        User $actor,
    ): LoyaltyRule {
        $before = $rule->only(self::AUDITED);

        $rule->forceFill([
            ...$data,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'is_active' => true,
            'created_by' => $actor->getKey(),
        ])->save();

        if ($rule->wasChanged()) {
            $this->audit->record(
                action: 'loyalty_rule.corrected',
                entity: $rule,
                before: $before,
                after: $rule->only(self::AUDITED),
                actor: $actor,
            );
        }

        return $rule->refresh();
    }
}
