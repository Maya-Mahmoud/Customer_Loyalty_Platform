<?php

namespace App\Http\Resources;

use App\Services\MerchantStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The supervisor's view of a merchant: the full registration record plus the
 * review trail, which is what the queue of BRD FR-ADM-01 lists.
 *
 * @property-read \App\Models\Merchant $resource
 */
class AdminMerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'commercial_register' => $this->commercial_register,
            'owner_name' => $this->owner_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'currency' => $this->currency,

            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),

            // A request becomes reviewable once the email address is proven. The
            // phone is captured but not verified, and stays visibly unconfirmed
            // here so the supervisor can weigh that before approving.
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'is_verified' => $this->email_verified_at !== null,

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'activated_at' => $this->activated_at?->toIso8601String(),

            'subscription_plan' => SubscriptionPlanResource::make($this->whenLoaded('subscriptionPlan')),
            'subscription_ends_at' => $this->subscription_ends_at?->toDateString(),

            'branches_count' => $this->whenCounted('branches'),
            'users_count' => $this->whenCounted('users'),

            /*
             * Whether the owner can actually get in yet. An activated account
             * whose owner never followed the invitation is unusable, and nothing
             * else on this screen would reveal that.
             *
             * The invitation token itself is never exposed: handing it to the
             * supervisor would let them set the owner's password and take over
             * the merchant.
             */
            'owner' => $this->whenLoaded('owner', fn () => [
                'name' => $this->owner?->name,
                'email' => $this->owner?->email,
                'status' => $this->owner?->status->value,
                'has_password' => $this->owner?->password !== null,
                'invitation_expires_at' => $this->owner?->invitation_expires_at?->toIso8601String(),
            ]),

            // BRD BR-020: the date before which suspended data must be kept.
            'retention_floor' => app(MerchantStatusService::class)->retentionFloor($this->resource),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
