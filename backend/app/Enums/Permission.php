<?php

namespace App\Enums;

/**
 * One case per row of the permission matrix in BRD 7.2. Keeping them as an enum
 * means a typo in a policy check fails at parse time instead of silently
 * granting nothing.
 */
enum Permission: string
{
    case ManageMerchantStatus = 'merchants.manage_status';
    case ManageBranches = 'branches.manage';
    case ManageUsers = 'users.manage';
    case ManageLoyaltyRules = 'loyalty_rules.manage';
    case RegisterCustomer = 'customers.register';
    case CreateInvoice = 'invoices.create';
    case LookupCustomer = 'customers.lookup';
    case AmendInvoice = 'invoices.amend';
    case RedeemDiscount = 'redemptions.create';

    /**
     * Accepting a purchase voucher at the till.
     *
     * An amendment to BRD 7.2, which lists no such row. Redeeming a discount is
     * restricted to a manager or the owner (BR-013), but a voucher's value was
     * already authorised when it was issued, so accepting one is open to a sales
     * rep too — otherwise every reward would need a manager at the counter.
     */
    case AcceptVoucher = 'vouchers.accept';
    /**
     * Editing the store's own profile — its trade name, city and logo.
     *
     * An amendment to BRD 7.2, which has no row for it although FR-MER-06 asks for
     * the logo and FR-MER-05 for the currency. The owner alone holds it: these are
     * the details a customer sees, and a branch manager changing the trade name
     * would be changing the brand.
     */
    case ManageStoreProfile = 'merchant.profile';
    case AdjustBalance = 'ledger.adjust';
    case ViewAllBranchReports = 'reports.view_all_branches';
    case ViewOwnBranchReports = 'reports.view_own_branch';
    case ViewAuditLog = 'audit_logs.view';
    case ExportCustomers = 'customers.export';

    /**
     * Erasing a customer at their request (BRD FR-CUS-10, section 16).
     *
     * A third amendment to BRD 7.2: FR-CUS-10 requires the capability and the matrix
     * names nobody for it. The owner alone, because the act cannot be undone — the
     * only thing that could reverse it is the personal data it removes — and because
     * a data subject request is answered by the business, not by a till.
     */
    case AnonymizeCustomer = 'customers.anonymize';
}
