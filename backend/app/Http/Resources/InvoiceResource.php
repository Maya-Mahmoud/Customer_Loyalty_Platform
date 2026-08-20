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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
