<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Enforces the subscription caps of BRD FR-ADM-04.
 *
 * BRD 8.2 wants the addition blocked and an upgrade offered, so the guards refuse
 * rather than silently truncate, and usage() feeds the screen that shows how much
 * of the plan is left — the caps are visible before the user hits them.
 *
 * A merchant with no plan assigned is treated as unlimited. Assigning one is the
 * supervisor's job, and blocking a store because that has not happened yet would
 * punish them for someone else's omission.
 */
class PlanLimitService
{
    public function guardBranchCreation(Merchant $merchant): void
    {
        $cap = $merchant->subscriptionPlan?->max_branches;

        if ($cap !== null && $this->branchCount($merchant) >= $cap) {
            throw new ConflictHttpException(__(
                'Your plan allows :count branches. Upgrade the subscription to add another.',
                ['count' => $cap],
            ));
        }
    }

    public function guardUserCreation(Merchant $merchant): void
    {
        $cap = $merchant->subscriptionPlan?->max_users;

        if ($cap !== null && $this->userCount($merchant) >= $cap) {
            throw new ConflictHttpException(__(
                'Your plan allows :count users. Upgrade the subscription to add another.',
                ['count' => $cap],
            ));
        }
    }

    /**
     * What the store has used and what it is allowed. A null cap means unlimited.
     *
     * @return array{branches: array{used: int, max: int|null}, users: array{used: int, max: int|null}, plan: string|null}
     */
    public function usage(Merchant $merchant): array
    {
        $plan = $merchant->subscriptionPlan;

        return [
            'branches' => [
                'used' => $this->branchCount($merchant),
                'max' => $plan?->max_branches,
            ],
            'users' => [
                'used' => $this->userCount($merchant),
                'max' => $plan?->max_users,
            ],
            'plan' => $plan?->name,
        ];
    }

    /**
     * Disabled branches still count: they hold history and can be switched back
     * on, so they are not free capacity.
     */
    private function branchCount(Merchant $merchant): int
    {
        return Branch::withoutGlobalScopes()
            ->where('merchant_id', $merchant->getKey())
            ->whereNull('deleted_at')
            ->count();
    }

    private function userCount(Merchant $merchant): int
    {
        return User::withoutGlobalScopes()
            ->where('merchant_id', $merchant->getKey())
            ->whereNull('deleted_at')
            ->count();
    }
}
