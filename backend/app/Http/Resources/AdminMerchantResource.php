<?php

namespace App\Http\Resources;

use App\Enums\MerchantStatus;
use App\Services\MerchantStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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

            /*
             * Whether the missing verification actually stands in the way of
             * approval — the server's own verdict, not the raw fact above.
             *
             * The review screen used to derive this itself from `is_verified`, and
             * the two fell out of step the moment verification became a setting
             * (config/clp.php): the server would have approved the request while
             * the screen kept the button greyed out, with a sentence above it
             * explaining a rule that no longer applied. A rule enforced in one
             * place has to be published from that place.
             */
            'blocked_by_verification' => $this->status === MerchantStatus::Pending
                && $this->email_verified_at === null
                && (bool) config('clp.require_email_verification'),

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
                /*
                 * The picture the owner set for themselves.
                 *
                 * A face beside a name is how the supervisor recognises the person
                 * they are about to activate or suspend, rather than reading an
                 * address off a form. It discloses nothing new — the name and the
                 * address are already on this screen, and the owner uploaded the
                 * picture to be seen.
                 */
                'avatar_url' => $this->owner?->avatar_path === null
                    ? null
                    : Storage::disk('public')->url($this->owner->avatar_path),
                'status' => $this->owner?->status->value,
                'has_password' => $this->owner?->password !== null,
                'invitation_expires_at' => $this->owner?->invitation_expires_at?->toIso8601String(),
            ]),

            /*
             * The shop's own logo (FR-MER-06), for the header of this record.
             *
             * The owner uploaded it to be the shop's face, and this screen showed a
             * generic storefront glyph instead — so every shop looked identical at
             * the top of the page the supervisor decides on.
             */
            'logo_url' => $this->logo_path === null
                ? null
                : Storage::disk('public')->url($this->logo_path),

            // BRD BR-020: the date before which suspended data must be kept.
            'retention_floor' => app(MerchantStatusService::class)->retentionFloor($this->resource),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
