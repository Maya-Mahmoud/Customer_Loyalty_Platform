import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  Branch,
  BranchForm,
  PlanUsage,
  StaffForm,
  StaffMember,
} from '../models/staff.model';
import { ApiService } from './api.service';

/**
 * Branches and staff of the signed-in merchant (BRD 8.2).
 *
 * Every call here is refused by the server for any role but the store owner, and
 * scoped to their own merchant regardless of the ids sent.
 */
@Injectable({ providedIn: 'root' })
export class StaffService {
  private readonly api = inject(ApiService);

  // Branches -------------------------------------------------------------

  branches(): Observable<Branch[]> {
    return this.api.get<{ data: Branch[] }>('branches').pipe(map((r) => r.data));
  }

  usage(): Observable<PlanUsage> {
    return this.api.get<PlanUsage>('branches/usage');
  }

  createBranch(form: BranchForm): Observable<Branch> {
    return this.api.post<{ data: Branch }>('branches', form).pipe(map((r) => r.data));
  }

  updateBranch(id: number, form: BranchForm): Observable<Branch> {
    return this.api.put<{ data: Branch }>(`branches/${id}`, form).pipe(map((r) => r.data));
  }

  /** Branches are switched off rather than deleted, so history stays attached. */
  setBranchActive(id: number, active: boolean): Observable<Branch> {
    return this.api
      .post<{ data: Branch }>(`branches/${id}/${active ? 'enable' : 'disable'}`)
      .pipe(map((r) => r.data));
  }

  // Staff ----------------------------------------------------------------

  staff(): Observable<StaffMember[]> {
    return this.api.get<{ data: StaffMember[] }>('staff').pipe(map((r) => r.data));
  }

  createStaff(form: StaffForm): Observable<StaffMember> {
    return this.api.post<{ data: StaffMember }>('staff', form).pipe(map((r) => r.data));
  }

  updateStaff(id: number, form: Partial<StaffForm>): Observable<StaffMember> {
    return this.api.put<{ data: StaffMember }>(`staff/${id}`, form).pipe(map((r) => r.data));
  }

  setStaffActive(id: number, active: boolean): Observable<StaffMember> {
    return this.api
      .post<{ data: StaffMember }>(`staff/${id}/${active ? 'enable' : 'disable'}`)
      .pipe(map((r) => r.data));
  }

  /** The link goes to the user's own mailbox; it is never returned here. */
  resendInvitation(id: number): Observable<StaffMember> {
    return this.api
      .post<{ data: StaffMember }>(`staff/${id}/resend-invitation`)
      .pipe(map((r) => r.data));
  }
}
