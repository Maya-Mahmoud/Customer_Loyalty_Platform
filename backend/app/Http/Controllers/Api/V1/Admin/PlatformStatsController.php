<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MerchantStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;

/**
 * Headline counters for the supervisor's dashboard (BRD FR-ADM-01). Kept separate
 * from the merchant list so the list stays a plain paginated query.
 */
class PlatformStatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $byStatus = Merchant::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (MerchantStatus::cases() as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        return response()->json([
            'merchants' => $counts,
            'total' => array_sum($counts),
            // The queue length is what the supervisor actually acts on.
            'awaiting_review' => Merchant::where('status', MerchantStatus::Pending)
                ->whereNotNull('submitted_at')
                ->count(),
            'expiring_soon' => Merchant::where('status', MerchantStatus::Active)
                ->whereNotNull('subscription_ends_at')
                ->whereBetween('subscription_ends_at', [
                    now()->toDateString(),
                    now()->addDays((int) config('clp.subscription_expiry_warning_days'))->toDateString(),
                ])
                ->count(),
        ]);
    }
}
