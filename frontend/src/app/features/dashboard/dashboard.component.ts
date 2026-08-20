import { Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';

import { ReportSummary } from '../../core/models/report.model';
import { AuthService } from '../../core/services/auth.service';
import { ReportService } from '../../core/services/report.service';

/**
 * The home screen (BRD FR-RPT-01).
 *
 * Two audiences, one screen. A manager or owner opens the app to see today's
 * numbers, so those come first. A sales rep holds no reports permission and gets the
 * shortcut to the till instead — the same reason the navigation hides what a role
 * cannot use rather than disabling it.
 */
@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [
    RouterLink,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatIconModule,
    MatProgressSpinnerModule,
  ],
  templateUrl: './dashboard.component.html',
})
export class DashboardComponent {
  private readonly auth = inject(AuthService);
  private readonly reports = inject(ReportService);

  readonly user = this.auth.user;
  readonly merchant = this.auth.merchant;
  readonly branch = this.auth.branch;

  readonly loading = signal(false);
  readonly today = signal<ReportSummary | null>(null);
  readonly month = signal<ReportSummary | null>(null);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  readonly canSeeNumbers = computed(() => this.auth.has('reports.view_own_branch'));
  readonly canSell = computed(() => this.auth.has('invoices.create'));
  readonly canLookup = computed(() => this.auth.has('customers.lookup'));

  constructor() {
    if (this.canSeeNumbers()) {
      this.load();
    }
  }

  private load(): void {
    const today = this.format(new Date());
    const now = new Date();

    this.loading.set(true);

    /*
     * Two windows, because "how is today going" and "how is the month going" are
     * different questions and a manager asks both. The server scopes each to the
     * branch the role allows, so nothing here has to.
     */
    this.reports.summary({ from: today, to: today }).subscribe({
      next: (report) => {
        this.today.set(report.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });

    this.reports
      .summary({
        from: this.format(new Date(now.getFullYear(), now.getMonth(), 1)),
        to: today,
      })
      .subscribe({
        next: (report) => this.month.set(report.data),
        error: () => this.month.set(null),
      });
  }

  /** Local date parts, not toISOString, which shifts the day across time zones. */
  private format(date: Date): string {
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
  }
}
