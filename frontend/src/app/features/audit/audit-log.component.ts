import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { TranslateModule } from '@ngx-translate/core';

import { AuditLogEntry, AuditLogService, AuditLogStats } from '../../core/services/audit-log.service';
import { DonutChartComponent, DonutSlice } from '../../shared/charts/donut-chart.component';
import { HourBarsComponent } from '../../shared/charts/hour-bars.component';
import { AuthService } from '../../core/services/auth.service';

/** One field of a before/after pair, flattened for display. */
interface ChangeLine {
  key: string;
  before: string | null;
  after: string | null;
}

/**
 * Reading the audit trail (BRD FR-SEC-02, section 20).
 *
 * The screen renders before/after generically rather than templating the fields of
 * each action. A trail that only displayed what somebody remembered to template
 * would quietly hide the rest — and the entries worth finding are the unusual ones.
 *
 * Newest first, filtered by action family, person and date, and read-only: there is
 * nothing here that edits an entry, because there is no endpoint to edit one.
 */
@Component({
  selector: 'app-audit-log',
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
    DonutChartComponent,
    HourBarsComponent,
  ],
  templateUrl: './audit-log.component.html',
})
export class AuditLogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly logs = inject(AuditLogService);
  private readonly auth = inject(AuthService);

  readonly loading = signal(true);
  readonly entries = signal<AuditLogEntry[]>([]);
  readonly actions = signal<string[]>([]);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly stats = signal<AuditLogStats | null>(null);

  /** Only the platform supervisor sees entries from more than one store. */
  readonly showsMerchant = computed(() => this.auth.role() === 'platform_admin');

  readonly form = this.fb.nonNullable.group({
    action: [''],
    from: [''],
    to: [''],
  });

  /**
   * The five commonest actions plus the tail as one slice.
   *
   * Composed here rather than on the server so the "other" bucket keeps its own
   * label in whichever language is on screen.
   */
  readonly actionSlices = computed<DonutSlice[]>(() => {
    const stats = this.stats();

    if (stats === null) {
      return [];
    }

    const slices: DonutSlice[] = stats.by_action.map((row) => ({
      label: row.action,
      value: row.total,
    }));

    if (stats.other_total > 0) {
      slices.push({ label: this.otherLabel, value: stats.other_total });
    }

    return slices;
  });

  readonly hasCharts = computed(() => (this.stats()?.total ?? 0) > 0);

  private readonly otherLabel = 'أخرى / Other';

  constructor() {
    this.logs.actions().subscribe({
      next: (actions) => this.actions.set(actions),
      error: () => this.actions.set([]),
    });

    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    this.page.set(page);

    const raw = this.form.getRawValue();

    // Fetched alongside the page, from the same filters.
    this.logs.stats(raw).subscribe({
      next: (stats) => this.stats.set(stats),
      error: () => this.stats.set(null),
    });

    this.logs.page({ ...raw, page }).subscribe({
      next: (result) => {
        this.entries.set(result.data);
        this.lastPage.set(result.meta.last_page);
        this.total.set(result.meta.total);
        this.loading.set(false);
      },
      error: () => {
        this.entries.set([]);
        this.loading.set(false);
      },
    });
  }

  reset(): void {
    this.form.reset({ action: '', from: '', to: '' });
    this.load();
  }

  /**
   * Flattens before and after into one list of changed fields, so a reader sees
   * "50 → 40" rather than two blocks of JSON to compare by eye.
   */
  changes(entry: AuditLogEntry): ChangeLine[] {
    const keys = new Set([
      ...Object.keys(entry.before ?? {}),
      ...Object.keys(entry.after ?? {}),
    ]);

    return [...keys].map((key) => ({
      key,
      before: this.display(entry.before?.[key]),
      after: this.display(entry.after?.[key]),
    }));
  }

  /** Anything not a scalar is shown as JSON: readable beats absent. */
  private display(value: unknown): string | null {
    if (value === null || value === undefined) {
      return null;
    }

    if (typeof value === 'object') {
      return JSON.stringify(value);
    }

    if (typeof value === 'boolean') {
      return value ? '✓' : '✗';
    }

    return String(value);
  }
}
