import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTableModule } from '@angular/material/table';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslateModule } from '@ngx-translate/core';
import { Observable } from 'rxjs';

import { Branch, BranchForm, PlanUsage } from '../../../core/models/staff.model';
import { NotificationService } from '../../../core/services/notification.service';
import { StaffService } from '../../../core/services/staff.service';
import { BranchDialogComponent } from './branch-dialog.component';

/**
 * Branch setup for the store owner (BRD 8.2 step 1, FR-BRN-01).
 *
 * Usage against the plan sits at the top so the cap of FR-ADM-04 is visible before
 * it is hit, and the add button goes away rather than failing on submit.
 */
@Component({
  selector: 'app-branch-list',
  standalone: true,
  imports: [
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
  templateUrl: './branch-list.component.html',
})
export class BranchListComponent {
  private readonly staff = inject(StaffService);
  private readonly dialog = inject(MatDialog);
  private readonly notifications = inject(NotificationService);

  readonly columns = ['name', 'city', 'contact', 'users', 'status', 'actions'];

  readonly branches = signal<Branch[]>([]);
  readonly usage = signal<PlanUsage | null>(null);
  readonly loading = signal(true);
  readonly working = signal(false);

  /** Hides the add button once the plan is full, instead of failing on submit. */
  readonly atCap = computed(() => {
    const limits = this.usage()?.branches;

    return limits?.max !== null && limits !== undefined && limits.used >= limits.max;
  });

  readonly activeCount = computed(() => this.branches().filter((b) => b.is_active).length);

  constructor() {
    this.load();
  }

  add(): void {
    this.dialog
      .open<BranchDialogComponent, Branch | null, BranchForm>(BranchDialogComponent, {
        data: null,
        width: '480px',
      })
      .afterClosed()
      .subscribe((form) => {
        if (form) {
          this.apply(this.staff.createBranch(form), 'branches.added');
        }
      });
  }

  edit(branch: Branch): void {
    this.dialog
      .open<BranchDialogComponent, Branch | null, BranchForm>(BranchDialogComponent, {
        data: branch,
        width: '480px',
      })
      .afterClosed()
      .subscribe((form) => {
        if (form) {
          this.apply(this.staff.updateBranch(branch.id, form), 'branches.updated');
        }
      });
  }

  toggle(branch: Branch): void {
    this.apply(
      this.staff.setBranchActive(branch.id, !branch.is_active),
      branch.is_active ? 'branches.disabled' : 'branches.enabled'
    );
  }

  private apply(request: Observable<Branch>, successKey: string): void {
    this.working.set(true);

    request.subscribe({
      next: () => {
        this.notifications.success(successKey);
        this.load();
      },
      // The interceptor has already shown the reason — a plan cap, the last
      // active branch, staff still assigned. Reloading resyncs the screen.
      error: (_response: HttpErrorResponse) => {
        this.working.set(false);
        this.load();
      },
    });
  }

  private load(): void {
    this.loading.set(true);

    this.staff.branches().subscribe({
      next: (branches) => {
        this.branches.set(branches);
        this.loading.set(false);
        this.working.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.working.set(false);
      },
    });

    this.staff.usage().subscribe({
      next: (usage) => this.usage.set(usage),
      error: () => this.usage.set(null),
    });
  }
}
