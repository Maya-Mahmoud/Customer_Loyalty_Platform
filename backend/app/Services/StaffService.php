<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Branches and staff accounts of one merchant (BRD 8.2, FR-BRN-01 to FR-BRN-06).
 *
 * Most of what follows is refusals. The screens are simple, but a store can lock
 * itself out of its own account in several ways — removing the last owner, turning
 * off the last branch, disabling yourself — and none of those are recoverable
 * without the platform supervisor editing the database. Each one is blocked here
 * with a message that says what to do instead.
 */
class StaffService
{
    public function __construct(
        private readonly PlanLimitService $limits,
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
    ) {
    }

    // -----------------------------------------------------------------
    // Branches
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBranch(Merchant $merchant, array $data): Branch
    {
        $this->limits->guardBranchCreation($merchant);

        // Set explicitly rather than left to the column default, which would not
        // be reflected on the instance returned to the caller.
        $branch = Branch::create($data + ['is_active' => true]);

        $this->audit->record('branch.created', entity: $branch, after: $branch->only(['name', 'city']));

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBranch(Branch $branch, array $data): Branch
    {
        $original = $branch->getOriginal();

        $branch->update($data);

        if ($branch->wasChanged()) {
            $this->audit->recordChange('branch.updated', $branch, $original);
        }

        return $branch;
    }

    /**
     * Branches are switched off, never deleted: their invoices and their history
     * stay attached (the same principle as BRD BR-010 for invoices).
     */
    public function setBranchActive(Branch $branch, bool $active): Branch
    {
        if (! $active) {
            $this->guardBranchCanBeDisabled($branch);
        }

        $original = $branch->getOriginal();

        $branch->update(['is_active' => $active]);

        $this->audit->recordChange($active ? 'branch.enabled' : 'branch.disabled', $branch, $original);

        return $branch;
    }

    // -----------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------

    /**
     * Creates a staff account and invites them to set their own password
     * (BRD FR-BRN-04). No password is ever chosen on their behalf.
     *
     * @param  array<string, mixed>  $data
     */
    public function createUser(Merchant $merchant, array $data): User
    {
        $this->limits->guardUserCreation($merchant);

        $role = UserRole::from($data['role']);
        $branchId = $this->resolveBranchId($role, $data['branch_id'] ?? null);

        $user = DB::transaction(function () use ($data, $role, $branchId): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'branch_id' => $branchId,
                'role' => $role,
                'status' => UserStatus::Invited,
                'password' => null,
            ]);

            $this->invitations->invite($user);

            return $user;
        });

        $this->audit->record(
            action: 'user.created',
            entity: $user,
            after: $user->only(['name', 'email', 'role', 'branch_id']),
        );

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateUser(User $user, array $data, User $actor): User
    {
        $original = $user->getOriginal();

        if (array_key_exists('role', $data)) {
            $role = UserRole::from($data['role']);

            $this->guardRoleChange($user, $role, $actor);

            $data['branch_id'] = $this->resolveBranchId($role, $data['branch_id'] ?? $user->branch_id);
        } elseif (array_key_exists('branch_id', $data)) {
            // BRD FR-BRN-06: a move is just a new branch. History stays intact
            // because every invoice records the branch it was entered at.
            $data['branch_id'] = $this->resolveBranchId($user->role, $data['branch_id']);
        }

        $user->update($data);

        if ($user->wasChanged()) {
            $this->audit->recordChange('user.updated', $user, $original);
        }

        return $user;
    }

    /**
     * Disabling keeps every invoice the user entered attached to them
     * (BRD FR-BRN-05); nothing is deleted or reassigned.
     */
    public function setUserActive(User $user, bool $active, User $actor): User
    {
        if (! $active) {
            $this->guardUserCanBeDisabled($user, $actor);
        }

        $original = $user->getOriginal();

        $user->update([
            // Someone who never accepted their invitation goes back to Invited,
            // not Active — they still have no password.
            'status' => $active
                ? ($user->password === null ? UserStatus::Invited : UserStatus::Active)
                : UserStatus::Disabled,
        ]);

        if (! $active) {
            // Sessions in flight would otherwise outlive the decision.
            $user->tokens()->delete();
        }

        $this->audit->recordChange($active ? 'user.enabled' : 'user.disabled', $user, $original);

        return $user;
    }

    /**
     * Sends a fresh invitation to a user who has still not set a password.
     */
    public function resendInvitation(User $user): User
    {
        if ($user->password !== null) {
            throw new ConflictHttpException(
                __('This user has already set a password. They can reset it themselves from the sign-in page.')
            );
        }

        $this->invitations->invite($user);

        $this->audit->record('user.invitation_resent', entity: $user, after: ['email' => $user->email]);

        return $user;
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    /**
     * A branch-bound role needs a branch; an owner spans all of them and must not
     * be pinned to one (BRD FR-BRN-03, 7.1).
     */
    private function resolveBranchId(UserRole $role, mixed $branchId): ?int
    {
        if (! $role->isBranchBound()) {
            return null;
        }

        if ($branchId === null) {
            throw new ConflictHttpException(__('This role must be assigned to a branch.'));
        }

        return (int) $branchId;
    }

    /**
     * Without an active branch nobody can record a sale, so the last one cannot be
     * switched off. Staff still assigned to it are moved first, deliberately by
     * hand — silently reassigning people would hide the decision.
     */
    private function guardBranchCanBeDisabled(Branch $branch): void
    {
        if (! $branch->is_active) {
            return;
        }

        $remaining = Branch::where('is_active', true)->whereKeyNot($branch->getKey())->count();

        if ($remaining === 0) {
            throw new ConflictHttpException(
                __('This is the only active branch. Add another before switching it off.')
            );
        }

        $assigned = User::where('branch_id', $branch->getKey())
            ->where('status', '!=', UserStatus::Disabled)
            ->count();

        if ($assigned > 0) {
            throw new ConflictHttpException(__(
                'Move the :count users assigned to this branch before switching it off.',
                ['count' => $assigned],
            ));
        }
    }

    private function guardUserCanBeDisabled(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new ConflictHttpException(__('You cannot disable your own account.'));
        }

        $this->guardLastOwner($user, __('This is the only active owner. Appoint another before disabling this one.'));
    }

    private function guardRoleChange(User $user, UserRole $role, User $actor): void
    {
        if (! $role->isAssignableByOwner()) {
            throw new ConflictHttpException(__('This role cannot be assigned.'));
        }

        if ($user->is($actor) && $role !== $user->role) {
            // Demoting yourself could remove the last owner in one click, and it is
            // never what someone means to do to their own account.
            throw new ConflictHttpException(__('You cannot change your own role.'));
        }

        if ($role !== UserRole::MerchantOwner) {
            $this->guardLastOwner($user, __('This is the only active owner. Appoint another before changing this role.'));
        }
    }

    /**
     * A merchant with no active owner can no longer manage its own branches,
     * users or loyalty rules — only the platform supervisor could undo it.
     */
    private function guardLastOwner(User $user, string $message): void
    {
        if ($user->role !== UserRole::MerchantOwner || $user->status !== UserStatus::Active) {
            return;
        }

        $otherOwners = User::where('role', UserRole::MerchantOwner)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($user->getKey())
            ->count();

        if ($otherOwners === 0) {
            throw new ConflictHttpException($message);
        }
    }
}
