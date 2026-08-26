/** What a customer has accumulated in the open cycle, derived from the ledger. */
export interface CycleState {
  total_amount: number;
  invoice_count: number;
  is_eligible: boolean;
  /** Null when the rule does not track money. */
  amount_remaining: number | null;
  /** Null when the rule does not count visits. */
  invoices_remaining: number | null;
  /** 0 to 1, for the progress bar of BRD FR-CUS-06. */
  progress: number;
  threshold_amount?: number | null;
  threshold_invoice_count?: number | null;
  min_invoice_amount?: string;
  reward_type?: string;
}

export interface CustomerInvoice {
  id: number;
  invoice_number: string;
  amount: string;
  invoice_date: string | null;
  status: 'active' | 'cancelled';
  /** False when it fell under the rule's minimum (BRD BR-003). */
  qualifies_for_accumulation: boolean;
  branch?: string | null;
  entered_by?: string | null;
  created_at: string | null;
  /** A correction request waiting for a decision (BRD 8.7). */
  pending_correction?: boolean;
  /** Already returned on a partial return (BRD FR-INV-07); '0.00' when none. */
  returned_amount?: string;
}

/** The card of BRD FR-CUS-05 — everything the rep sees on lookup. */
export interface CustomerCard {
  id: number;
  phone: string;
  name: string | null;
  consent_status: 'not_collected' | 'granted' | 'withdrawn';
  last_purchase_at: string | null;
  current_cycle_number: number;
  redemptions_count: number;
  /** Null until the merchant publishes a loyalty rule. */
  cycle: CycleState | null;
  invoices?: CustomerInvoice[];
}

export interface LookupResult {
  found: boolean;
  customer: CustomerCard | null;
}

export interface RegisterCustomerForm {
  phone: string;
  name: string;
  consent_given: boolean;
  branch_id?: number | null;
}

export interface InvoiceForm {
  invoice_number: string;
  amount: number;
  invoice_date: string;
  customer_id: number | null;
  branch_id?: number | null;
}

export interface InvoiceResult {
  message: string;
  data: CustomerInvoice;
  /** False when the sale was recorded but accumulates nothing. */
  counted: boolean;
  cycle: CycleState | null;
}

/**
 * Paying a reward out (BRD 8.6).
 *
 * Money arrives as a two-decimal string, the shape the API sends everywhere, so a
 * figure is never reformatted or rounded a second time on the way to the screen.
 */
export interface RedemptionReward {
  reward_type: 'percentage' | 'fixed_amount' | 'voucher';
  /** Before the cap of BR-021; shown beside the paid figure to explain it. */
  computed_amount: string;
  discount_amount: string;
  was_capped: boolean;
  carried_over_amount: string;
}

/** What the manager confirms, fetched before anything is paid (BRD 8.6 step 4). */
export interface RedemptionPreview {
  eligible: boolean;
  reward: RedemptionReward | null;
  cycle?: {
    cycle_number: number;
    total_amount: string;
    invoice_count: number;
  };
}

export interface RedemptionVoucher {
  code: string;
  amount: string;
  expires_at: string | null;
}

/** One paid reward, for the receipt and for the history of FR-RED-07. */
export interface Redemption {
  id: number;
  cycle_number: number;
  cycle_total_amount: string;
  cycle_invoice_count: number;
  reward_type: 'percentage' | 'fixed_amount' | 'voucher';
  computed_amount: string;
  discount_amount: string;
  was_capped: boolean;
  carried_over_amount: string;
  is_override: boolean;
  override_reason: string | null;
  redeemed_at: string | null;
  branch?: string | null;
  performed_by?: string | null;
  voucher?: RedemptionVoucher | null;
}

export interface RedeemForm {
  /** BR-014: paying a customer who has not qualified, on the owner's authority. */
  override?: boolean;
  override_reason?: string | null;
  branch_id?: number | null;
}

export interface RedeemResult {
  message: string;
  data: Redemption;
}

/**
 * Cancelling and returning invoices (BRD 8.7).
 *
 * A rep raises the request and a manager decides it (BR-012), so the same record is
 * read from both sides: the till sees its own request pending, the manager sees a
 * queue of them.
 */
export type CorrectionType = 'cancel' | 'full_return' | 'partial_return';

export interface CorrectionForm {
  type: CorrectionType;
  /** Only a partial return carries one. */
  amount?: number | null;
  reason: string;
}

export interface InvoiceCorrection {
  id: number;
  invoice_id: number;
  type: CorrectionType;
  amount: string | null;
  reason: string;
  status: 'pending' | 'approved' | 'rejected';
  review_note: string | null;
  reviewed_at: string | null;
  created_at: string | null;
  requested_by?: string | null;
  reviewed_by?: string | null;
  invoice?: {
    id: number;
    invoice_number: string;
    amount: string;
    invoice_date: string | null;
    status: 'active' | 'cancelled';
    branch: string | null;
    customer: { id: number; name: string | null; phone: string } | null;
  };
}

export interface CorrectionResult {
  message: string;
  data: InvoiceCorrection;
  /** True when the person requesting could also approve, so it took effect at once. */
  applied: boolean;
}

/**
 * Correcting a balance by hand (BRD 7.2, ledger.adjust). Negative deducts.
 *
 * The escape hatch the owner alone holds: without a legitimate way to put a number
 * right, staff invent a fake invoice instead (AF-01).
 */
export interface AdjustmentForm {
  amount: number;
  reason: string;
  branch_id?: number | null;
}

export interface AdjustmentResult {
  message: string;
  data: {
    id: number;
    amount: string;
    note: string | null;
    cycle_number: number;
  };
  /** The balance afterwards, which is the number the owner was aiming at. */
  balance: number | null;
}

export interface Adjustment {
  id: number;
  amount: string;
  note: string | null;
  cycle_number: number;
  branch: string | null;
  created_by: string | null;
  created_at: string | null;
}
