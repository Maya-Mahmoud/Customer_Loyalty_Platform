/** Mirrors the backend enums of BRD 11.1. */
export type ThresholdType = 'amount' | 'invoice_count' | 'both';
export type RewardType = 'percentage' | 'fixed_amount' | 'voucher';
export type AccumulationScope = 'merchant' | 'branch';
export type ResetPolicy = 'carry_over' | 'full_reset';

/**
 * One version of a merchant's rule. Rules are never edited — a change publishes
 * the next version and closes this one (BRD BR-015, FR-LOY-08).
 */
export interface LoyaltyRule {
  id: number;
  version: number;

  threshold_type: ThresholdType;
  threshold_amount: string | null;
  threshold_invoice_count: number | null;

  reward_type: RewardType;
  reward_value: string;
  max_discount_amount: string | null;
  min_invoice_amount: string;

  accumulation_scope: AccumulationScope;
  reset_policy: ResetPolicy;
  balance_validity_months: number | null;
  voucher_validity_days: number | null;

  effective_from: string | null;
  /** Null on the version in force; set on every superseded one. */
  effective_to: string | null;
  is_active: boolean;

  created_by?: string | null;
  created_at: string | null;
}

/** What the form submits. Numbers here, not the strings the API returns. */
export interface LoyaltyRuleForm {
  threshold_type: ThresholdType;
  threshold_amount: number | null;
  threshold_invoice_count: number | null;

  reward_type: RewardType;
  reward_value: number;
  max_discount_amount: number | null;
  min_invoice_amount: number;

  accumulation_scope: AccumulationScope;
  reset_policy: ResetPolicy;
  balance_validity_months: number | null;
  voucher_validity_days: number | null;

  effective_from?: string;
}

export interface LoyaltyRuleState {
  /** Null until the owner publishes for the first time. */
  current: LoyaltyRule | null;
  history: LoyaltyRule[];
  /** The starting values of BRD 11.1, so a fresh form is never empty. */
  defaults: LoyaltyRuleForm;
}
