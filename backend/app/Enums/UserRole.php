<?php

namespace App\Enums;

/**
 * The four actors described in BRD 7.1. Each role owns its permission list so
 * the matrix of BRD 7.2 lives in exactly one place.
 */
enum UserRole: string
{
    case PlatformAdmin = 'platform_admin';
    case MerchantOwner = 'merchant_owner';
    case BranchManager = 'branch_manager';
    case SalesRep = 'sales_rep';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::PlatformAdmin => [
                Permission::ManageMerchantStatus,
                Permission::ViewAuditLog,
            ],
            self::MerchantOwner => [
                Permission::ManageBranches,
                Permission::ManageUsers,
                Permission::ManageLoyaltyRules,
                Permission::RegisterCustomer,
                Permission::CreateInvoice,
                Permission::LookupCustomer,
                Permission::AmendInvoice,
                Permission::RedeemDiscount,
                Permission::AdjustBalance,
                Permission::ViewAllBranchReports,
                Permission::ViewOwnBranchReports,
                Permission::ViewAuditLog,
                Permission::ExportCustomers,
            ],
            self::BranchManager => [
                Permission::RegisterCustomer,
                Permission::CreateInvoice,
                Permission::LookupCustomer,
                Permission::AmendInvoice,
                Permission::RedeemDiscount,
                Permission::ViewOwnBranchReports,
            ],
            self::SalesRep => [
                Permission::RegisterCustomer,
                Permission::CreateInvoice,
                Permission::LookupCustomer,
            ],
        };
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }

    public function isPlatformAdmin(): bool
    {
        return $this === self::PlatformAdmin;
    }

    /**
     * Platform admins operate the platform itself and are the only role that is
     * not tied to a merchant record.
     */
    public function requiresMerchant(): bool
    {
        return $this !== self::PlatformAdmin;
    }

    /**
     * Roles that only ever see their own branch (BRD FR-BRN-03).
     */
    public function isBranchBound(): bool
    {
        return $this === self::BranchManager || $this === self::SalesRep;
    }
}
