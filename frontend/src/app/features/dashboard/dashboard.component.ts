import { Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';

import { MyRecentInvoice } from '../../core/models/sales.model';
import { ReportSummary } from '../../core/models/report.model';
import { PlatformStats } from '../../core/models/merchant.model';
import { AdminMerchantService } from '../../core/services/admin-merchant.service';
import { SalesService } from '../../core/services/sales.service';
import { AuthService } from '../../core/services/auth.service';
import { ReportService } from '../../core/services/report.service';

/**
 * The home screen (BRD FR-RPT-01, FR-ADM-01).
 *
 * Three audiences, one route, and each gets the thing they opened the app for. A
 * store owner or manager gets today's numbers. The platform supervisor gets the
 * review queue, because a pending registration is somebody waiting. A sales rep holds
 * neither permission and gets the shortcut to the till.
 *
 * Nothing here is fetched that the role cannot read: a rep's dashboard makes no
 * requests at all, which is also why it is instant on a slow connection (RSK-04).
 */
@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [
    RouterLink,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatDividerModule,
    MatIconModule,
    MatProgressSpinnerModule,
  ],
  templateUrl: './dashboard.component.html',
})
export class DashboardComponent {
  private readonly auth = inject(AuthService);
  private readonly reports = inject(ReportService);
  private readonly admin = inject(AdminMerchantService);
  private readonly sales = inject(SalesService);

  readonly user = this.auth.user;
  readonly merchant = this.auth.merchant;
  readonly branch = this.auth.branch;

  readonly loading = signal(false);
  readonly today = signal<ReportSummary | null>(null);
  readonly month = signal<ReportSummary | null>(null);
  readonly stats = signal<PlatformStats | null>(null);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  readonly canSeeNumbers = computed(() => this.auth.has('reports.view_own_branch'));
  readonly isPlatformAdmin = computed(() => this.auth.has('merchants.manage_status'));
  readonly canSell = computed(() => this.auth.has('invoices.create'));
  readonly canLookup = computed(() => this.auth.has('customers.lookup'));

  /** The queue is the supervisor's actual job, so it drives the whole panel. */
  readonly hasQueue = computed(() => (this.stats()?.awaiting_review ?? 0) > 0);

  /**
   * The rep own entries for today.
   *
   * The one figure this screen can honestly show somebody who may not read a
   * total: their own work. BR-019 keeps a rep away from the customer list and the
   * branch takings, and none of that is here — no names, no shop total, only what
   * this person entered since this morning.
   */
  readonly myRecent = signal<MyRecentInvoice[]>([]);

  readonly myCountToday = computed(() => this.myRecent().filter((i) => !i.cancelled).length);

  /**
   * Whether to show which shop and branch this account is working in.
   *
   * For whoever has no figures to read. An owner opening this screen gets their
   * takings; a sales rep used to get two tiles and an empty page, and the question
   * that page could not answer was the simplest one — which shop is this, and who
   * do I go to.
   */
  readonly showsPlace = computed(() => this.merchant() !== null && !this.canSeeNumbers());

  constructor() {
    if (this.isPlatformAdmin()) {
      this.loadPlatform();
    } else if (this.canSeeNumbers()) {
      this.loadStore();
    }

    // Their own day, for whoever records sales. Silent on failure: this is context
    // beside the two buttons, and an error banner over it would be louder than the
    // thing it failed to fetch.
    if (this.canSell()) {
      this.sales.myRecentInvoices().subscribe({
        next: (invoices) => this.myRecent.set(invoices),
        error: () => this.myRecent.set([]),
      });
    }
  }

  private loadPlatform(): void {
    this.loading.set(true);

    this.admin.stats().subscribe({
      next: (stats) => {
        this.stats.set(stats);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  private loadStore(): void {
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
      .summary({ from: this.format(new Date(now.getFullYear(), now.getMonth(), 1)), to: today })
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
