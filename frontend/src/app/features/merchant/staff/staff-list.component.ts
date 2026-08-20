import { Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTableModule } from '@angular/material/table';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { Observable, forkJoin } from 'rxjs';

import { Branch, PlanUsage, StaffForm, StaffMember } from '../../../core/models/staff.model';
import { AuthService } from '../../../core/services/auth.service';
import { NotificationService } from '../../../core/services/notification.service';
import { StaffService } from '../../../core/services/staff.service';
import { StaffDialogComponent, StaffDialogData } from './staff-dialog.component';

/**
 * Staff setup for the store owner (BRD 8.2 step 2, FR-BRN-02 to FR-BRN-06).
 *
 * Accounts are disabled, never deleted, so the invoices each person entered stay
 * attached to them (BRD FR-BRN-05).
 */
@Component({
  selector: 'app-staff-list',
  standalone: true,
  imports: [
    RouterLink,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatDialogModule,
    MatIconModule,
    MatMenuModule,
    MatProgressSpinnerModule,
    MatTableModule,
    MatTooltipModule,
  ],
  templateUrl: './staff-list.component.html',
})
export class StaffListComponent {
  private readonly staff = inject(StaffService);
  private readonly dialog = inject(MatDialog);
  private readonly notifications = inject(NotificationService);
  private readonly auth = inject(AuthService);

  readonly columns = ['name', 'role', 'branch', 'access', 'actions'];

  readonly members = signal<StaffMember[]>([]);
  readonly branches = signal<Branch[]>([]);
  readonly usage = signal<PlanUsage | null>(null);
  readonly loading = signal(true);
  readonly working = signal(false);

  readonly currentUserId = computed(() => this.auth.user()?.id ?? null);

  readonly atCap = computed(() => {
    const limits = this.usage()?.users;

    return limits?.max !== null && limits !== undefined && limits.used >= limits.max;
  });

  /** No branches, no branch-bound staff — so adding anyone is pointless yet. */
  readonly hasActiveBranch = computed(() => this.branches().some((b) => b.is_active));

  private readonly activeOwners = computed(
    () => this.members().filter((m) => m.role === 'merchant_owner' && m.status === 'active').length
  );

  constructor() {
    this.load();
  }

  /**
   * Mirrors the server guards so an action that would be refused is never offered:
   * you cannot disable yourself, and the last active owner has to stay.
   */
  canDisable(member: StaffMember): boolean {
    if (member.id === this.currentUserId()) {
      return false;
    }

    return !(member.role === 'merchant_owner' && member.status === 'active' && this.activeOwners() <= 1);
  }

  canEdit(member: StaffMember): boolean {
    return member.id !== this.currentUserId();
  }

  add(): void {
    this.openDialog(null).subscribe((form) => {
      if (form) {
        this.apply(this.staff.createStaff(form as StaffForm), 'staff.added');
      }
    });
  }

  edit(member: StaffMember): void {
    this.openDialog(member).subscribe((form) => {
      if (form) {
        this.apply(this.staff.updateStaff(member.id, form), 'staff.updated');
      }
    });
  }

  toggle(member: StaffMember): void {
    const enabling = member.status === 'disabled';

    this.apply(
      this.staff.setStaffActive(member.id, enabling),
      enabling ? 'staff.enabled' : 'staff.disabled'
    );
  }

  resendInvitation(member: StaffMember): void {
    this.apply(this.staff.resendInvitation(member.id), 'staff.invitationSent');
  }

  private openDialog(member: StaffMember | null): Observable<Partial<StaffForm> | undefined> {
    return this.dialog
      .open<StaffDialogComponent, StaffDialogData, Partial<StaffForm>>(StaffDialogComponent, {
        data: { member, branches: this.branches() },
        width: '480px',
      })
      .afterClosed();
  }

  private apply(request: Observable<StaffMember>, successKey: string): void {
    this.working.set(true);

    request.subscribe({
      next: () => {
        this.notifications.success(successKey);
        this.load();
      },
      // The interceptor has already reported the reason — plan cap, last owner,
      // your own account. Reloading brings the screen back in step.
      error: () => {
        this.working.set(false);
        this.load();
      },
    });
  }

  private load(): void {
    this.loading.set(true);

    forkJoin({
      members: this.staff.staff(),
      branches: this.staff.branches(),
      usage: this.staff.usage(),
    }).subscribe({
      next: ({ members, branches, usage }) => {
        this.members.set(members);
        this.branches.set(branches);
        this.usage.set(usage);
        this.loading.set(false);
        this.working.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.working.set(false);
      },
    });
  }
}
