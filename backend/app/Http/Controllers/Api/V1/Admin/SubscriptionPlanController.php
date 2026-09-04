<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubscriptionPlanRequest;
use App\Http\Requests\Admin\UpdateSubscriptionPlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionPlanController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): AnonymousResourceCollection
    {
        return SubscriptionPlanResource::collection(
            SubscriptionPlan::where('is_active', true)->orderBy('monthly_price')->get()
        );
    }

    /**
     * A new plan on the price list (BRD FR-ADM-04).
     *
     * Created active, because a plan nobody can be put on is not a plan yet — the
     * supervisor adding one is adding something to sell. Retiring it later is an
     * update, not a delete: shops point at it by foreign key.
     */
    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $plan = SubscriptionPlan::create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        $this->audit->record(
            action: 'platform.plan_created',
            entity: $plan,
            after: $plan->only([
                'code', 'name', 'monthly_price',
                'max_branches', 'max_users', 'max_monthly_invoices',
            ]),
        );

        return response()->json([
            'message' => __('The plan has been added.'),
            'data' => SubscriptionPlanResource::make($plan),
        ], 201);
    }

    /**
     * What a plan costs and what it allows (BRD FR-ADM-04).
     *
     * The code is not editable and is not in the request. A shop is on a plan by its
     * foreign key, so renaming the code would break nothing in the data and every
     * report, seeder and conversation that refers to "the silver plan" by name.
     *
     * A price change is deliberately not retroactive and touches no merchant row:
     * the shops on this plan keep the subscription end date they already had, and the
     * new price applies from their next renewal. Repricing the platform should never
     * be able to cut a subscription somebody already paid for.
     */
    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        $original = $plan->only([
            'monthly_price', 'max_branches', 'max_users', 'max_monthly_invoices',
        ]);

        $plan->update($request->validated());

        if ($plan->wasChanged()) {
            $this->audit->recordChange('platform.plan_updated', $plan, $original);
        }

        return response()->json([
            'message' => __('The plan has been saved.'),
            'data' => SubscriptionPlanResource::make($plan->refresh()),
        ]);
    }
}
