<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

/**
 * Transcribes the permission matrix of BRD 7.2 cell by cell.
 *
 * The point is that the table below is read from the document, not from the code:
 * if someone widens a role in UserRole, this test fails and forces the document
 * and the implementation back into agreement.
 */
class PermissionMatrixTest extends TestCase
{
    /**
     * @return array<string, array{Permission, list<UserRole>}>
     */
    public static function matrix(): array
    {
        $admin = UserRole::PlatformAdmin;
        $owner = UserRole::MerchantOwner;
        $manager = UserRole::BranchManager;
        $rep = UserRole::SalesRep;

        return [
            'activate or suspend a merchant' => [Permission::ManageMerchantStatus, [$admin]],
            'create and edit branches' => [Permission::ManageBranches, [$owner]],
            'create users' => [Permission::ManageUsers, [$owner]],
            'configure loyalty rules' => [Permission::ManageLoyaltyRules, [$owner]],
            'register a new customer' => [Permission::RegisterCustomer, [$owner, $manager, $rep]],
            'enter an invoice' => [Permission::CreateInvoice, [$owner, $manager, $rep]],
            'look up a customer' => [Permission::LookupCustomer, [$owner, $manager, $rep]],
            'edit or cancel an invoice' => [Permission::AmendInvoice, [$owner, $manager]],
            'redeem a discount' => [Permission::RedeemDiscount, [$owner, $manager]],

            /*
             * Not a row in BRD 7.2 — an amendment, recorded here so it stays a
             * deliberate decision rather than a drift.
             *
             * A voucher is spent on a later visit, and its value was already
             * authorised when a manager issued it. Requiring a manager again at
             * the till would make the reward unusable in practice, so a sales rep
             * may accept one. Issuing a discount remains restricted (BR-013).
             */
            'accept a purchase voucher' => [Permission::AcceptVoucher, [$owner, $manager, $rep]],
            /*
             * Not a row in BRD 7.2 either — a second amendment, recorded the same
             * way. FR-MER-06 asks for a store logo and FR-MER-05 for a currency, so
             * something has to own those settings; the matrix names nobody. The
             * owner holds it alone because these are the details a customer sees,
             * and a branch manager editing the trade name would be editing the brand.
             */
            'edit the store profile and logo' => [Permission::ManageStoreProfile, [$owner]],
            'adjust a balance manually' => [Permission::AdjustBalance, [$owner]],
            'view reports for all branches' => [Permission::ViewAllBranchReports, [$owner]],
            'view reports for own branch' => [Permission::ViewOwnBranchReports, [$owner, $manager]],
            'view the audit log' => [Permission::ViewAuditLog, [$admin, $owner]],
            'export customer data' => [Permission::ExportCustomers, [$owner]],

            /*
             * A third amendment, on the same footing as the two above. FR-CUS-10 and
             * section 16 require an erasure path and BRD 7.2 names nobody for it. The
             * owner alone: the act cannot be undone, and a data subject request is
             * answered by the business rather than at a till.
             */
            'erase a customer on request' => [Permission::AnonymizeCustomer, [$owner]],
        ];
    }

    /**
     * @param  list<UserRole>  $allowed
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function test_permission_is_granted_to_exactly_the_documented_roles(
        Permission $permission,
        array $allowed,
    ): void {
        foreach (UserRole::cases() as $role) {
            $expected = in_array($role, $allowed, strict: true);

            $this->assertSame(
                $expected,
                $role->has($permission),
                sprintf(
                    '%s should %sbe allowed to %s',
                    $role->value,
                    $expected ? '' : 'not ',
                    $permission->value,
                ),
            );
        }
    }

    public function test_a_sales_rep_can_never_amend_redeem_or_report(): void
    {
        // The separation of duties behind BRD BR-012, BR-013 and BR-019.
        $forbidden = [
            Permission::AmendInvoice,
            Permission::RedeemDiscount,
            Permission::AdjustBalance,
            Permission::ViewOwnBranchReports,
            Permission::ViewAllBranchReports,
            Permission::ExportCustomers,
            Permission::ViewAuditLog,
        ];

        foreach ($forbidden as $permission) {
            $this->assertFalse(UserRole::SalesRep->has($permission), $permission->value);
        }
    }

    public function test_only_the_platform_admin_lives_outside_a_merchant(): void
    {
        $this->assertFalse(UserRole::PlatformAdmin->requiresMerchant());

        foreach ([UserRole::MerchantOwner, UserRole::BranchManager, UserRole::SalesRep] as $role) {
            $this->assertTrue($role->requiresMerchant(), $role->value);
        }
    }

    public function test_only_managers_and_reps_are_confined_to_one_branch(): void
    {
        $this->assertTrue(UserRole::BranchManager->isBranchBound());
        $this->assertTrue(UserRole::SalesRep->isBranchBound());
        $this->assertFalse(UserRole::MerchantOwner->isBranchBound());
        $this->assertFalse(UserRole::PlatformAdmin->isBranchBound());
    }
}
