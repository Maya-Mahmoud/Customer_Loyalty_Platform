<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Concerns\BelongsToMerchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Staff of the platform and of the merchants (BRD 7.1).
 *
 * The end customer is deliberately absent from this table: they are never a user
 * of the system and never hold an account (BRD BR-001).
 */
class User extends Authenticatable
{
    use BelongsToMerchant, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'branch_id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Never serialised: the hash is what makes an invitation link work.
        'invitation_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function enteredInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function registeredCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'registered_by_user_id');
    }

    // ---------------------------------------------------------------------
    // Authorisation — the matrix of BRD 7.2 is answered from the role enum.
    // ---------------------------------------------------------------------

    public function hasPermission(Permission $permission): bool
    {
        return $this->role->has($permission);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role->isPlatformAdmin();
    }

    public function isMerchantOwner(): bool
    {
        return $this->role === UserRole::MerchantOwner;
    }

    /**
     * Whether this user may act on data belonging to the given branch. Owners
     * span every branch; managers and reps are confined to their own
     * (BRD FR-BRN-03).
     */
    public function canAccessBranch(?int $branchId): bool
    {
        if (! $this->role->isBranchBound()) {
            return true;
        }

        return $branchId !== null && $this->branch_id === $branchId;
    }

    /**
     * Sign-in is refused unless both the user and the merchant they belong to are
     * active (BRD FR-ADM-03).
     */
    public function canSignIn(): bool
    {
        if (! $this->status->allowsAccess()) {
            return false;
        }

        if (! $this->role->requiresMerchant()) {
            return true;
        }

        return $this->merchant !== null && $this->merchant->isActive();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    public function scopeWithRole(Builder $query, UserRole $role): void
    {
        $query->where('role', $role);
    }
}
