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
            'adjust a balance manually' => [Permission::AdjustBalance, [$owner]],
            'view reports for all branches' => [Permission::ViewAllBranchReports, [$owner]],
            'view reports for own branch' => [Permission::ViewOwnBranchReports, [$owner, $manager]],
            'view the audit log' => [Permission::ViewAuditLog, [$admin, $owner]],
            'export customer data' => [Permission::ExportCustomers, [$owner]],
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
