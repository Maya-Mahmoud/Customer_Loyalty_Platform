import { UserRole, UserStatus } from './auth.model';

export interface Branch {
  id: number;
  name: string;
  city: string;
  address: string | null;
  phone: string | null;
  is_active: boolean;
  /** Present on the list; explains why a branch with staff cannot be switched off. */
  users_count?: number;
}

export interface BranchForm {
  name: string;
  city: string;
  address: string | null;
  phone: string | null;
}

export interface StaffMember {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: UserRole;
  status: UserStatus;
  branch_id: number | null;
  last_login_at: string | null;
  /** False while the invitation has not been accepted; drives the resend button. */
  has_password: boolean;
  invitation_expires_at: string | null;
  branch: Branch | null;
}

export interface StaffForm {
  name: string;
  email: string;
  phone: string | null;
  role: UserRole;
  branch_id: number | null;
}

/** A null cap means unlimited (BRD FR-ADM-04). */
export interface PlanUsage {
  branches: { used: number; max: number | null };
  users: { used: number; max: number | null };
  plan: string | null;
}
