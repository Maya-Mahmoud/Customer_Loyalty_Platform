import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { MatTableModule } from '@angular/material/table';
import { MatTabsModule } from '@angular/material/tabs';
import { TranslateModule } from '@ngx-translate/core';

import {
  ReportBranchRow,
  ReportCustomers,
  ReportPeriod,
  ReportQuery,
  ReportRewards,
  ReportStaffRow,
  ReportSummary,
} from '../../core/models/report.model';
import { Branch } from '../../core/models/staff.model';
import { AuthService } from '../../core/services/auth.service';
import { NotificationService } from '../../core/services/notification.service';
import { ReportService } from '../../core/services/report.service';
import { StaffService } from '../../core/services/staff.service';

/**
 * The reports of BRD 9 (RPT-01 to RPT-05) on one screen.
 *
 * One period, five reports. Splitting them across five screens would mean choosing
 * the dates five times, and a store owner comparing "sales this month" with
 * "discounts this month" has to know both figures cover the same days.
 *
 * The five are fetched together and the period shown is the one the server used, not
 * the one typed in — a branch manager is answered with their own branch (FR-BRN-03),
 * and the header has to say so rather than imply otherwise.
 */
@Component({
  selector: 'app-reports',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressSpinnerModule,
    MatSelectModule,
    MatTableModule,
    MatTabsModule,
  ],
  templateUrl: './reports.component.html',
})
export class ReportsComponent {
  private readonly fb = inject(FormBuilder);
  private readonly reports = inject(ReportService);
  private readonly staff = inject(StaffService);
  private readonly auth = inject(AuthService);
  private readonly notifications = inject(NotificationService);

  readonly branchColumns = ['branch', 'sales', 'invoices', 'average', 'customers', 'rewards'];
  readonly staffColumns = ['name', 'sales', 'invoices', 'average', 'customers', 'corrections'];
  readonly customerColumns = ['name', 'phone', 'total', 'invoices'];

  readonly loading = signal(true);
  readonly period = signal<ReportPeriod | null>(null);

  readonly summary = signal<ReportSummary | null>(null);
  readonly branchRows = signal<ReportBranchRow[]>([]);
  readonly rewards = signal<ReportRewards | null>(null);
  readonly customers = signal<ReportCustomers | null>(null);
  readonly staffRows = signal<ReportStaffRow[]>([]);

  readonly branches = signal<Branch[]>([]);
  readonly exporting = signal(false);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  /** Only someone who can see every branch is offered the choice. */
  readonly canPickBranch = computed(() => this.auth.has('reports.view_all_branches'));
  /** BR-019: the owner alone may take the list away, and it is logged when they do. */
  readonly canExport = computed(() => this.auth.has('customers.export'));

  readonly form = this.fb.nonNullable.group({
    from: [this.startOfMonth()],
    to: [this.today()],
    branch_id: [null as number | null],
  });

  constructor() {
    if (this.canPickBranch()) {
      this.staff.branches().subscribe({
        next: (branches) => this.branches.set(branches),
        error: () => this.branches.set([]),
      });
    }

    this.load();
  }

  load(): void {
    const query: ReportQuery = this.form.getRawValue();

    this.loading.set(true);

    /*
     * Five separate requests rather than one combined endpoint: each is a plain
     * aggregate the database answers quickly, and a slow one does not hold up the
     * numbers at the top of the screen.
     */
    this.reports.summary(query).subscribe({
      next: (report) => {
        this.summary.set(report.data);
        // Taken from the summary, which every role can read, so the header states
        // the window that actually applied.
        this.period.set(report.period);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });

    this.reports.branches(query).subscribe({
      next: (report) => this.branchRows.set(report.data),
      error: () => this.branchRows.set([]),
    });

    this.reports.rewards(query).subscribe({
      next: (report) => this.rewards.set(report.data),
      error: () => this.rewards.set(null),
    });

    this.reports.customers(query).subscribe({
      next: (report) => this.customers.set(report.data),
      error: () => this.customers.set(null),
    });

    this.reports.staff(query).subscribe({
      next: (report) => this.staffRows.set(report.data),
      error: () => this.staffRows.set([]),
    });
  }

  /** Quick ranges, because most questions are about this month or last. */
  applyPreset(preset: 'month' | 'lastMonth' | 'week' | 'year'): void {
    const now = new Date();
    let from: Date;
    let to = now;

    switch (preset) {
      case 'week':
        from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6);
        break;
      case 'lastMonth':
        from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        to = new Date(now.getFullYear(), now.getMonth(), 0);
        break;
      case 'year':
        from = new Date(now.getFullYear(), 0, 1);
        break;
      default:
        from = new Date(now.getFullYear(), now.getMonth(), 1);
    }

    this.form.patchValue({ from: this.format(from), to: this.format(to) });
    this.load();
  }

  private startOfMonth(): string {
    const now = new Date();

    return this.format(new Date(now.getFullYear(), now.getMonth(), 1));
  }

  private today(): string {
    return this.format(new Date());
  }

  /** Local date parts, not toISOString, which shifts the day across time zones. */
  private format(date: Date): string {
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
  }

  /**
   * Exporting the customer base (BRD BR-019).
   *
   * The period on screen is the range exported, so what the owner sees and what they
   * download cannot disagree. onlyConsented narrows it to the customers who agreed to
   * be contacted, which is the file a campaign should be built from (section 16).
   */
  exportCustomers(onlyConsented: boolean): void {
    if (this.exporting()) {
      return;
    }

    this.exporting.set(true);

    const query = { ...this.form.getRawValue(), only_consented: onlyConsented };

    this.reports.exportCustomers(query).subscribe({
      next: (blob) => {
        this.save(blob, `customers-${this.form.controls.to.value}.csv`);
        this.notifications.success('reports.exportDone');
        this.exporting.set(false);
      },
      error: () => this.exporting.set(false),
    });
  }

  /** Hands the blob to the browser as a download, then releases the object URL. */
  private save(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.click();

    URL.revokeObjectURL(url);
  }
}
