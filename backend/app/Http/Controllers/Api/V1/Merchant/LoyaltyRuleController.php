<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreLoyaltyRuleRequest;
use App\Http\Resources\LoyaltyRuleResource;
use App\Models\LoyaltyRule;
use App\Services\Loyalty\LoyaltyRuleService;
use Illuminate\Http\JsonResponse;

/**
 * The loyalty rule of the signed-in merchant (BRD 8.3, FR-LOY-01 to FR-LOY-08).
 *
 * Behind can:loyalty_rules.manage — the store owner only, per BRD 7.2. There is no
 * update route on purpose: a change publishes a new version, because BR-015 forbids
 * a rule from taking effect retroactively.
 */
class LoyaltyRuleController extends Controller
{
    public function __construct(private readonly LoyaltyRuleService $rules)
    {
    }

    /**
     * The version in force today, plus every superseded one for reference.
     */
    public function index(): JsonResponse
    {
        $merchant = request()->user()->merchant;
        $current = $this->rules->current($merchant);

        return response()->json([
            'current' => $current !== null
                ? LoyaltyRuleResource::make($current->load('createdBy'))
                : null,
            'history' => LoyaltyRuleResource::collection($this->rules->history($merchant)),
            'defaults' => LoyaltyRule::defaultsForDisplay(),
        ]);
    }

    /**
     * Publishes a new version. The previous one is closed the day before this one
     * starts, so no date is ever governed by two rules.
     */
    public function store(StoreLoyaltyRuleRequest $request): JsonResponse
    {
        $rule = $this->rules->publish(
            merchant: $request->user()->merchant,
            data: $request->validated(),
            actor: $request->user(),
        );

        return response()->json([
            'message' => __('The loyalty rule is published and applies to sales from :date.', [
                'date' => $rule->effective_from->toDateString(),
            ]),
            'data' => LoyaltyRuleResource::make($rule->load('createdBy')),
        ], 201);
    }
}
