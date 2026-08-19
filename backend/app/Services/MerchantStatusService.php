<?php

namespace App\Services;

use App\Enums\MerchantStatus;
use App\Mail\MerchantDecisionMail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The merchant account state machine of BRD 8.1 and FR-ADM-02.
 *
 * Every transition is written to the audit log, and the two that hurt — rejection
 * and suspension — refuse to run without a written reason.
 */
class MerchantStatusService
{
    /**
     * Which statuses each transition may start from. Anything else is a conflict
     * rather than a silent no-op, so a double click cannot re-activate a merchant
     * and reset their review trail.
     */
    private const TRANSITIONS = [
        'activate' => [MerchantStatus::Pending, MerchantStatus::Suspended],
        'reject' => [MerchantStatus::Pending],
        'suspend' => [MerchantStatus::Active],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InvitationService $invitations,
    ) {
    }

    /**
     * Approves a pending registration, or lifts a suspension.
     */
    public function activate(Merchant $merchant, User $actor): Merchant
    {
        $this->guard($merchant, 'activate');

        $wasPending = $merchant->status === MerchantStatus::Pending;

        if ($wasPending && ! $this->isVerified($merchant)) {
            throw new ConflictHttpException(
                __('This request cannot be approved until the email address is verified.')
            );
        }

        $before = $merchant->only(['status', 'status_reason', 'activated_at']);

        $merchant->forceFill([
            'status' => MerchantStatus::Active,
            'status_reason' => null,
            'status_changed_at' => now(),
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            // Kept from the first activation so the account age stays true
            // across a later suspend-and-restore cycle.
            'activated_at' => $merchant->activated_at ?? now(),
        ])->save();

        /*
         * No invitation is sent. The owner chose their password while registering
         * and can sign in the moment this saves. Anyone who has somehow ended up
         * without one — a record from before this flow existed — is handled by the
         * explicit resend on the console.
         */

        $this->audit->record(
            action: 'merchant.activated',
            entity: $merchant,
            before: $before,
            after: $merchant->only(['status', 'activated_at']),
            actor: $actor,
        );

        Mail::to($merchant->email)->send(
            new MerchantDecisionMail($merchant, MerchantStatus::Active)
        );

        return $merchant;
    }

    /**
     * Declines a pending registration. BRD FR-ADM-02 makes the reason mandatory,
     * and BRD 8.1 allows the applicant to correct it and re-apply.
     */
    public function reject(Merchant $merchant, User $actor, string $reason): Merchant
    {
        $this->guard($merchant, 'reject');

        $before = $merchant->only(['status', 'status_reason']);

        $merchant->forceFill([
            'status' => MerchantStatus::Rejected,
            'status_reason' => $reason,
            'status_changed_at' => now(),
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'merchant.rejected',
            entity: $merchant,
            before: $before,
            after: $merchant->only(['status', 'status_reason']),
            actor: $actor,
        );

        Mail::to($merchant->email)->send(
            new MerchantDecisionMail($merchant, MerchantStatus::Rejected, $reason)
        );

        return $merchant;
    }

    /**
     * Cuts off access without touching data. BRD BR-020 keeps customers and
     * balances for at least 90 days, so nothing here deletes anything.
     */
    public function suspend(Merchant $merchant, User $actor, string $reason): Merchant
    {
        $this->guard($merchant, 'suspend');

        $before = $merchant->only(['status', 'status_reason']);

        DB::transaction(function () use ($merchant, $reason): void {
            $merchant->forceFill([
                'status' => MerchantStatus::Suspended,
                'status_reason' => $reason,
                'status_changed_at' => now(),
            ])->save();

            // Existing tokens would otherwise keep working until they expire;
            // EnsureAccountIsActive already blocks them, and revoking closes the
            // window entirely.
            $this->revokeTokens($merchant);
        });

        $this->audit->record(
            action: 'merchant.suspended',
            entity: $merchant,
            before: $before,
            after: $merchant->only(['status', 'status_reason']),
            actor: $actor,
        );

        Mail::to($merchant->email)->send(
            new MerchantDecisionMail($merchant, MerchantStatus::Suspended, $reason)
        );

        return $merchant;
    }

    /**
     * Sends the owner a fresh invitation link.
     *
     * Invitations expire, and an email can be lost or mistyped — without this the
     * only way back into an activated account would be a developer editing the
     * database, which BRD 7.1 does not contemplate.
     *
     * Deliberately narrow: it only applies while the owner has never set a
     * password. Re-inviting someone who already has one would flip them back to
     * Invited and lock them out of their own account. A forgotten password is a
     * different flow.
     *
     * The link itself is never returned to the caller. Handing it to the platform
     * supervisor would let them set an owner's password and take over the
     * merchant, which is exactly what the isolation of FR-ADM-06 exists to prevent.
     */
    public function resendOwnerInvitation(Merchant $merchant, User $actor): User
    {
        if (! $merchant->isActive()) {
            throw new ConflictHttpException(
                __('Activate the store account before inviting its owner.')
            );
        }

        $owner = $this->owner($merchant);

        if ($owner === null) {
            throw new ConflictHttpException(__('This store has no owner account.'));
        }

        if ($owner->password !== null) {
            throw new ConflictHttpException(
                __('The owner has already set a password and can sign in.')
            );
        }

        $this->invitations->invite($owner);

        $this->audit->record(
            action: 'merchant.owner_invitation_resent',
            entity: $merchant,
            after: ['owner_email' => $owner->email],
            actor: $actor,
        );

        return $owner;
    }

    /**
     * The date before which suspended data must not be archived or deleted
     * (BRD BR-020). Surfaced to the supervisor so the rule is visible, not buried.
     */
    public function retentionFloor(Merchant $merchant): ?string
    {
        if ($merchant->status !== MerchantStatus::Suspended || $merchant->status_changed_at === null) {
            return null;
        }

        return $merchant->status_changed_at
            ->copy()
            ->addDays((int) config('clp.suspended_retention_days'))
            ->toDateString();
    }

    private function guard(Merchant $merchant, string $transition): void
    {
        if (! in_array($merchant->status, self::TRANSITIONS[$transition], strict: true)) {
            throw new ConflictHttpException(__('This action does not apply to the current account status.'));
        }
    }

    /**
     * Only the email address is proven during registration; the owner's phone is
     * captured but not verified. See MerchantRegistrationService for why.
     */
    private function isVerified(Merchant $merchant): bool
    {
        return $merchant->email_verified_at !== null;
    }

    private function owner(Merchant $merchant): ?User
    {
        return User::withoutGlobalScopes()
            ->where('merchant_id', $merchant->getKey())
            ->where('role', \App\Enums\UserRole::MerchantOwner)
            ->first();
    }

    private function revokeTokens(Merchant $merchant): void
    {
        $userIds = User::withoutGlobalScopes()
            ->where('merchant_id', $merchant->getKey())
            ->pluck('id');

        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }
}
