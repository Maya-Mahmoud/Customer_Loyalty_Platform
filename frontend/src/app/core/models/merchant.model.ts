import { MerchantStatus, UserRole, UserStatus } from './auth.model';

/** The self-registration form of BRD 8.1 step 1, plus the two agreements. */
export interface RegistrationForm {
  name: string;
  trade_name: string | null;
  commercial_register: string;
  owner_name: string;
  email: string;
  phone: string;
  city: string;
  currency?: string;
  /** Chosen here, not through a link after activation. */
  password: string;
  password_confirmation: string;
  accepts_terms: boolean;
  accepts_data_processing: boolean;
}

export interface RegistrationStarted {
  message: string;
  /** Where the code went, echoed back so the screen can confirm it. */
  email: string;
  expires_in_minutes: number;
}

export interface SubscriptionPlan {
  id: number;
  code: string;
  name: string;
  /** Null means unlimited (BRD FR-ADM-04). */
  max_branches: number | null;
  max_users: number | null;
  max_monthly_invoices: number | null;
  monthly_price: string;
  is_active: boolean;
}

/** The supervisor's view of a merchant account (BRD FR-ADM-01). */
export interface AdminMerchant {
  id: number;
  name: string;
  trade_name: string | null;
  commercial_register: string;
  owner_name: string;
  email: string;
  phone: string;
  city: string;
  currency: string;

  status: MerchantStatus;
  status_reason: string | null;
  status_changed_at: string | null;

  email_verified_at: string | null;
  phone_verified_at: string | null;
  is_verified: boolean;

  submitted_at: string | null;
  reviewed_at: string | null;
  reviewed_by?: string | null;
  activated_at: string | null;

  subscription_plan: SubscriptionPlan | null;
  subscription_ends_at: string | null;

  branches_count?: number;
  users_count?: number;

  /**
   * Whether the owner can actually sign in yet. An activated account whose owner
   * never followed the invitation is unusable, and nothing else would show it.
   * The invitation token is deliberately never sent to this screen.
   */
  owner?: {
    name: string;
    email: string;
    status: UserStatus;
    has_password: boolean;
    invitation_expires_at: string | null;
  };

  /** Earliest date suspended data may be archived (BRD BR-020). */
  retention_floor: string | null;

  created_at: string | null;
}

export interface PlatformStats {
  merchants: Record<MerchantStatus, number>;
  total: number;
  awaiting_review: number;
  expiring_soon: number;
}

export interface InvitationDetails {
  name: string;
  email: string;
  role: UserRole;
  merchant_name: string | null;
}
