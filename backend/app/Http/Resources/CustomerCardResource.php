<?php

namespace App\Http\Resources;

use App\Services\Loyalty\CycleSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer card of BRD FR-CUS-05 — what the rep sees the moment a number is
 * entered, while the customer is standing there.
 *
 * Every figure is derived from the ledger, never read from a stored balance
 * (BRD 13.3), so the card and the redemption screen can never disagree.
 *
 * @property-read \App\Models\Customer $resource
 */
class CustomerCardResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly ?CycleSnapshot $snapshot = null,
        private readonly int $redemptionCount = 0,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'name' => $this->name,
            'consent_status' => $this->consent_status->value,
            'last_purchase_at' => $this->last_purchase_at?->toIso8601String(),
            'current_cycle_number' => $this->current_cycle_number,
            // BRD FR-RED-07: previous rewards, so the rep can answer "what did I
            // get last time" without leaving the screen.
            'redemptions_count' => $this->redemptionCount,

            /*
             * Null when the merchant has published no rule yet. The card still
             * works — the rep can record the sale — there is simply nothing to
             * accumulate towards.
             */
            'cycle' => $this->snapshot === null ? null : [
                'total_amount' => round($this->snapshot->totalAmount, 2),
                'invoice_count' => $this->snapshot->invoiceCount,
                'is_eligible' => $this->snapshot->isEligible(),
                'amount_remaining' => $this->snapshot->amountRemaining() !== null
                    ? round($this->snapshot->amountRemaining(), 2)
                    : null,
                'invoices_remaining' => $this->snapshot->invoicesRemaining(),
                // BRD FR-CUS-06: drives the progress bar.
                'progress' => round($this->snapshot->progress(), 4),
                'threshold_amount' => $this->snapshot->rule->threshold_type->tracksAmount()
                    ? round($this->snapshot->thresholdAmount(), 2)
                    : null,
                'threshold_invoice_count' => $this->snapshot->rule->threshold_type->tracksInvoiceCount()
                    ? $this->snapshot->thresholdCount()
                    : null,
                'min_invoice_amount' => $this->snapshot->rule->min_invoice_amount,
                'reward_type' => $this->snapshot->rule->reward_type->value,
            ],

            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
        ];
    }
}
