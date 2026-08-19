/**
 * Mirrors the backend enums. Kept as string unions rather than TS enums so the
 * values compare directly against what the API sends.
 */
export type UserRole = 'platform_admin' | 'merchant_owner' | 'branch_manager' | 'sales_rep';

export type UserStatus = 'invited' | 'active' | 'disabled';

export type MerchantStatus = 'pending' | 'active' | 'suspended' | 'rejected';

/**
 * One per row of the permission matrix in BRD 7.2. The strings must stay
 * identical to App\Enums\Permission on the server.
 */
export type Permission =
  | 'merchants.manage_status'
  | 'branches.manage'
  | 'users.manage'
  | 'loyalty_rules.manage'
  | 'customers.register'
  | 'invoices.create'
  | 'customers.lookup'
  | 'invoices.amend'
  | 'redemptions.create'
  | 'ledger.adjust'
  | 'reports.view_all_branches'
  | 'reports.view_own_branch'
  | 'audit_logs.view'
  | 'customers.export';

export interface AuthMerchant {
  id: number;
  name: string;
  trade_name: string | null;
  city: string;
  currency: string;
  logo_path: string | null;
  status: MerchantStatus;
  activated_at: string | null;
  subscription_ends_at: string | null;
}

export interface AuthBranch {
  id: number;
  name: string;
  city: string;
  address: string | null;
  phone: string | null;
  is_active: boolean;
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: UserRole;
  status: UserStatus;
  merchant_id: number | null;
  branch_id: number | null;
  last_login_at: string | null;
  /** Presentation only — the server re-checks every one of these. */
  permissions: Permission[];
  merchant: AuthMerchant | null;
  branch: AuthBranch | null;
}

export interface LoginCredentials {
  email: string;
  password: string;
  device_name?: string;
}

export interface LoginResponse {
  token: string;
  user: AuthUser;
}

export interface MeResponse {
  user: AuthUser;
}
