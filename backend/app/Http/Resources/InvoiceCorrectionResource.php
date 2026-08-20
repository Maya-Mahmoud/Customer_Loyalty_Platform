<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One correction request with its decision (BRD 8.7).
 *
 * The reason travels with it everywhere. A manager deciding on a request and an
 * auditor reading it a year later need the same sentence, so it is never summarised
 * away.
 *
 * @property-read \App\Models\InvoiceCorrection $resource
 */
class InvoiceCorrectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'type' => $this->type->value,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),

            // The queue of BRD FR-INV-08 is read invoice-first: a manager decides on
            // the sale, not on the request record.
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'amount' => $this->invoice->amount,
                'invoice_date' => $this->invoice->invoice_date?->toDateString(),
                'status' => $this->invoice->status->value,
                'branch' => $this->invoice->relationLoaded('branch') ? $this->invoice->branch?->name : null,
                'customer' => $this->invoice->relationLoaded('customer')
                    ? $this->invoice->customer?->only(['id', 'name', 'phone'])
                    : null,
            ]),
        ];
    }
}
