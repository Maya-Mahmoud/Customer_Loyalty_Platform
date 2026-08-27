<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recording a sale (BRD 8.4) — the most frequent operation in the system.
 *
 * The response carries everything the rep needs next: the saved invoice, whether
 * it counted, and the customer's position afterwards. BRD NFR-05 allows thirty
 * seconds for the whole entry, so a second request just to refresh the card would
 * spend a meaningful part of that budget.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        ['invoice' => $invoice, 'snapshot' => $snapshot, 'counted' => $counted] =
            $this->invoices->record($request->validated(), $request->user());

        return response()->json([
            'message' => $counted
                ? __('The sale has been recorded.')
                : $this->uncountedMessage($invoice->customer_id !== null),

            'data' => InvoiceResource::make($invoice->load('branch')),
            'counted' => $counted,

            /*
             * BRD FR-RED-01 and FR-RED-02: eligibility is re-evaluated after every
             * entry and reported immediately, so the rep can tell the customer while
             * they are still at the counter.
             */
            'cycle' => $snapshot === null ? null : [
                'total_amount' => round($snapshot->totalAmount, 2),
                'invoice_count' => $snapshot->invoiceCount,
                'is_eligible' => $snapshot->isEligible(),
                'amount_remaining' => $snapshot->amountRemaining() !== null
                    ? round($snapshot->amountRemaining(), 2)
                    : null,
                'invoices_remaining' => $snapshot->invoicesRemaining(),
                'progress' => round($snapshot->progress(), 4),
            ],
        ], 201);
    }

    /**
     * The caller's own entries from today, for the strip beside the till.
     *
     * No customer names and no branch totals: a rep may see what they themselves
     * keyed in, which is what catches a mistyped amount or a sale entered twice.
     * Anything wider would be the customer list BRD BR-019 keeps away from them.
     */
    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->invoices->recentFor($request->user())->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->amount,
                'counted' => $invoice->qualifies_for_accumulation,
                'cancelled' => $invoice->status === InvoiceStatus::Cancelled,
                'at' => $invoice->created_at?->format('H:i'),
            ]),
        ]);
    }

    /**
     * Why a sale was stored without counting. Saying so plainly at the counter is
     * what BRD 8.4 asks for in both of its alternative paths — the rep can explain
     * it to the customer straight away instead of fielding a complaint later.
     */
    private function uncountedMessage(bool $hasCustomer): string
    {
        return $hasCustomer
            // BRD BR-003, or no rule published yet.
            ? __('The sale has been recorded but does not count towards a reward.')
            // BRD BR-022: the customer declined to give a number.
            : __('The sale has been recorded without a customer, so it accumulates nothing.');
    }
}
