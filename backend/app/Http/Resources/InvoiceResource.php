<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Invoice $resource
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'amount' => $this->amount,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'status' => $this->status->value,
            // False when the amount was under the rule's minimum, so the reason it
            // did not count stays visible long after the fact (BRD BR-003).
            'qualifies_for_accumulation' => $this->qualifies_for_accumulation,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'entered_by' => $this->whenLoaded('enteredBy', fn () => $this->enteredBy?->name),

            /*
             * BRD 8.7: a request awaiting a decision is shown on the invoice itself,
             * so nobody raises a second one for the same sale, and so the customer
             * asking about it gets an answer at the counter.
             */
            'pending_correction' => $this->whenLoaded(
                'corrections',
                fn () => $this->corrections->contains(fn ($correction) => $correction->isPending())
            ),
            // What has already gone back on a partial return (BRD FR-INV-07). The
            // invoice keeps the amount that was actually keyed in (BR-010).
            'returned_amount' => $this->whenLoaded(
                'corrections',
                fn () => number_format(
                    (float) $this->corrections
                        ->filter(fn ($correction) => $correction->isApproved() && $correction->type->needsAmount())
                        ->sum('amount'),
                    2,
                    '.',
                    ''
                )
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
