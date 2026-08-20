<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreRedemptionRequest;
use App\Http\Resources\RedemptionResource;
use App\Models\Customer;
use App\Services\Loyalty\RedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paying a reward out (BRD 8.6).
 *
 * Behind can:redemptions.create, which by BRD 7.2 and BR-013 only a branch manager
 * or the owner holds — a sales rep records sales but never hands money back, which
 * is the separation of duties AF-08 asks for.
 */
class RedemptionController extends Controller
{
    public function __construct(private readonly RedemptionService $redemptions)
    {
    }

    /**
     * What the customer would receive, before anything is paid.
     *
     * BRD 8.6 step 4 asks for confirmation from the authorised user, and a figure
     * shown only after the fact cannot be confirmed.
     */
    public function preview(Request $request, Customer $customer): JsonResponse
    {
        $preview = $this->redemptions->preview($customer, $request->user()->branch_id);

        if ($preview === null) {
            return response()->json([
                'eligible' => false,
                'reward' => null,
            ]);
        }

        ['snapshot' => $snapshot, 'reward' => $reward] = $preview;

        /*
         * Money leaves as a two-decimal string, the same shape the decimal casts
         * give RedemptionResource. The preview and the receipt that follows it are
         * read side by side, so a figure must not change form between them.
         */
        return response()->json([
            'eligible' => true,
            'reward' => [
                'reward_type' => $reward->rewardType->value,
                'computed_amount' => $this->money($reward->computedAmount),
                'discount_amount' => $this->money($reward->discountAmount),
                // True when the cap of BR-021 bit, which is the one thing a
                // customer questions.
                'was_capped' => $reward->wasCapped(),
                'carried_over_amount' => $this->money($reward->carriedOverAmount),
            ],
            'cycle' => [
                'cycle_number' => $snapshot->cycleNumber,
                'total_amount' => $this->money($snapshot->totalAmount),
                'invoice_count' => $snapshot->invoiceCount,
            ],
        ]);
    }

    public function store(StoreRedemptionRequest $request, Customer $customer): JsonResponse
    {
        $redemption = $this->redemptions->redeem($customer, $request->user(), [
            'override' => $request->boolean('override'),
            'override_reason' => $request->input('override_reason'),
            'branch_id' => $request->input('branch_id'),
        ]);

        $redemption->load(['branch', 'performedBy', 'vouchers']);

        return response()->json([
            'message' => __('The reward has been paid and a new cycle has started.'),
            'data' => RedemptionResource::make($redemption),
        ], 201);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Past rewards with their dates and values (BRD FR-RED-07).
     */
    public function index(Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => RedemptionResource::collection(
                $this->redemptions->historyFor($customer)
            ),
        ]);
    }
}
