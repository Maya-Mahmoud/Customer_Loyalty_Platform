/**
 * The reports of BRD 9 (RPT-01 to RPT-05).
 *
 * Money is a two-decimal string throughout, as the API sends it — a report is read,
 * not calculated with, and re-parsing it on the client is how a total on the screen
 * stops matching the total in the database.
 */
export interface ReportQuery {
  from?: string;
  to?: string;
  branch_id?: number | null;
}

/** Echoed back with every report, so the figures and the dates cannot drift apart. */
export interface ReportPeriod {
  from: string;
  to: string;
  branch_id: number | null;
  /** True when the role confines the user to one branch (BRD FR-BRN-03). */
  branch_locked: boolean;
  days: number;
}

export interface Report<T> {
  period: ReportPeriod;
  data: T;
}

/** RPT-01 */
export interface ReportSummary {
  sales_total: string;
  invoice_count: number;
  average_invoice: string;
  customers_served: number;
  new_customers: number;
  redemption_count: number;
  discount_total: string;
  /** Discounts paid as a percentage of the sales that earned them. */
  discount_ratio: number;
  corrections_applied: number;
}

/** RPT-02 */
export interface ReportCustomers {
  total_customers: number;
  never_bought: number;
  inactive: number;
  inactive_since: string;
  top_customers: {
    customer_id: number;
    name: string | null;
    /** Masked unless the viewer holds customers.export (BRD BR-019). */
    phone: string;
    total: string;
    invoice_count: number;
  }[];
}

/** RPT-03 */
export interface ReportBranchRow {
  branch_id: number;
  branch: string;
  is_active: boolean;
  sales_total: string;
  invoice_count: number;
  average_invoice: string;
  customers_served: number;
  redemption_count: number;
  discount_total: string;
}

/** RPT-04 */
export interface ReportRewards {
  by_type: {
    reward_type: string;
    count: number;
    paid: string;
    /** Above `paid` wherever the cap of BR-021 bit. */
    computed: string;
  }[];
  override_count: number;
  override_total: string;
  vouchers: {
    issued_count: number;
    issued_total: string;
    used_count: number;
    used_total: string;
    /** Credit promised, unspent and not yet expired — a liability, measured now. */
    outstanding_count: number;
    outstanding_total: string;
  };
}

/** RPT-05 */
export interface ReportStaffRow {
  user_id: number;
  name: string;
  role: string;
  sales_total: string;
  invoice_count: number;
  average_invoice: string;
  customers_served: number;
  correction_count: number;
}
