<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreAdjustmentRequest;
use App\Models\Customer;
use App\Services\LedgerAdjustmentService;
use App\Services\Loyalty\LoyaltyEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correcting a balance by hand (BRD 7.2, ledger.adjust).
 *
 * The owner alone holds this gate. The response carries the balance afterwards,
 * because the only reason to make an adjustment is to reach a particular number and
 * the person making it should see that they did.
 */
class LedgerAdjustmentController extends Controller
{
    public function __construct(
        private readonly LedgerAdjustmentService $adjustments,
        private readonly LoyaltyEngine $engine,
    ) {
    }

    public function store(StoreAdjustmentRequest $request, Customer $customer): JsonResponse
    {
        $entry = $this->adjustments->adjust($customer, $request->validated(), $request->user());

        $snapshot = $this->engine->snapshot($customer->fresh());

        return response()->json([
            'message' => __('The balance has been adjusted.'),
            'data' => [
                'id' => $entry->id,
                'amount' => $entry->amount,
                'note' => $entry->note,
                'cycle_number' => $entry->cycle_number,
            ],
            'balance' => $snapshot === null ? null : round($snapshot->totalAmount, 2),
        ], 201);
    }

    public function index(Request $request, Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => $this->adjustments->historyFor($customer)->map(fn ($entry) => [
                'id' => $entry->id,
                'amount' => $entry->amount,
                'note' => $entry->note,
                'cycle_number' => $entry->cycle_number,
                'branch' => $entry->branch?->name,
                'created_by' => $entry->createdBy?->name,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
