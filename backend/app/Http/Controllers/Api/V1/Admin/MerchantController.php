<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\MerchantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignSubscriptionPlanRequest;
use App\Http\Requests\Admin\MerchantDecisionRequest;
use App\Http\Resources\AdminMerchantResource;
use App\Models\Merchant;
use App\Services\AuditLogger;
use App\Services\MerchantStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The supervisor's console over merchant accounts (BRD 9.1).
 *
 * Every route in this group sits behind can:merchants.manage_status, which by the
 * matrix of BRD 7.2 only the platform supervisor holds.
 */
class MerchantController extends Controller
{
    public function __construct(
        private readonly MerchantStatusService $status,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * BRD FR-ADM-01: every registration request with its status.
     *
     * Ordered so unreviewed requests surface first — the queue is the point of
     * the screen, not an alphabetical directory.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $merchants = Merchant::query()
            ->with('subscriptionPlan')
            ->withCount(['branches', 'users'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', MerchantStatus::from($request->string('status')->toString()))
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->toString().'%';

                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('trade_name', 'like', $term)
                    ->orWhere('commercial_register', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [MerchantStatus::Pending->value])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return AdminMerchantResource::collection($merchants);
    }

    /**
     * Reading a merchant's record for support purposes is itself logged
     * (BRD 7.1 and section 16).
     */
    public function show(Merchant $merchant): JsonResponse
    {
        $merchant->load(['subscriptionPlan', 'reviewedBy', 'owner'])->loadCount(['branches', 'users']);

        $this->audit->record('merchant.viewed_by_platform', entity: $merchant);

        return response()->json(['data' => AdminMerchantResource::make($merchant)]);
    }

    public function activate(Merchant $merchant): JsonResponse
    {
        $this->status->activate($merchant, request()->user());

        return $this->decided($merchant, __('The store account is now active.'));
    }

    public function reject(MerchantDecisionRequest $request, Merchant $merchant): JsonResponse
    {
        $this->status->reject($merchant, $request->user(), $request->string('reason')->toString());

        return $this->decided($merchant, __('The request was declined and the applicant has been notified.'));
    }

    public function suspend(MerchantDecisionRequest $request, Merchant $merchant): JsonResponse
    {
        $this->status->suspend($merchant, $request->user(), $request->string('reason')->toString());

        return $this->decided($merchant, __('The store account has been suspended.'));
    }

    /**
     * Sends the owner a fresh link to set their password. The link goes to the
     * registered address only — it is never shown here, since holding it would let
     * the supervisor take over the merchant's own account.
     */
    public function resendInvitation(Merchant $merchant): JsonResponse
    {
        $this->status->resendOwnerInvitation($merchant, request()->user());

        return $this->decided($merchant, __('A new invitation has been emailed to the store owner.'));
    }

    /**
     * BRD FR-ADM-04: the plan is what caps branches, users and monthly invoices.
     */
    public function assignPlan(AssignSubscriptionPlanRequest $request, Merchant $merchant): JsonResponse
    {
        $before = $merchant->only(['subscription_plan_id', 'subscription_ends_at']);

        $merchant->update($request->validated());

        $this->audit->record(
            action: 'merchant.plan_assigned',
            entity: $merchant,
            before: $before,
            after: $merchant->only(['subscription_plan_id', 'subscription_ends_at']),
        );

        return $this->decided($merchant, __('The subscription plan has been updated.'));
    }

    private function decided(Merchant $merchant, string $message): JsonResponse
    {
        $merchant->refresh()->load(['subscriptionPlan', 'reviewedBy', 'owner'])->loadCount(['branches', 'users']);

        return response()->json([
            'message' => $message,
            'data' => AdminMerchantResource::make($merchant),
        ]);
    }
}
