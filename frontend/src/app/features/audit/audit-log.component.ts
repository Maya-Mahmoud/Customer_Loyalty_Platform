import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslateModule, TranslateService } from '@ngx-translate/core';

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
    MatDatepickerModule,
    MatTooltipModule,
    DonutChartComponent,
    HourBarsComponent,
  ],
  templateUrl: './audit-log.component.html',
})
export class AuditLogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly logs = inject(AuditLogService);
  private readonly auth = inject(AuthService);
  private readonly translate = inject(TranslateService);

  readonly loading = signal(true);
  readonly entries = signal<AuditLogEntry[]>([]);
  readonly actions = signal<string[]>([]);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly stats = signal<AuditLogStats | null>(null);

  /** Only the platform supervisor sees entries from more than one store. */
  readonly showsMerchant = computed(() => this.auth.role() === 'platform_admin');

  readonly form = this.fb.group({
    action: this.fb.nonNullable.control(''),
    // Dates, not strings: the picker hands back a Date, and the API is given a
    // plain YYYY-MM-DD by filters() below.
    from: this.fb.control<Date | null>(null),
    to: this.fb.control<Date | null>(null),
  });

  /**
   * The filters as the API takes them.
   *
   * The conversion is by local calendar parts rather than toISOString(), which
   * converts to UTC first: east of Greenwich a date picked at midnight becomes the
   * previous day, and the trail would silently drop the entries of whichever day
   * the reader asked for.
   */
  private filters(): { action: string; from: string; to: string } {
    const raw = this.form.getRawValue();

    return {
      action: raw.action ?? '',
      from: this.asDate(raw.from),
      to: this.asDate(raw.to),
    };
  }

  private asDate(value: Date | null): string {
    if (value === null) {
      return '';
    }

    return [
      value.getFullYear(),
      String(value.getMonth() + 1).padStart(2, '0'),
      String(value.getDate()).padStart(2, '0'),
    ].join('-');
  }

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

    // Named in the legend, not coded: "auth.login" is an identifier, and the legend
    // is the one place a reader looks to find out what a slice is.
    const slices: DonutSlice[] = stats.by_action.map((row) => ({
      label: this.actionLabel(row.action),
      value: row.total,
    }));

    if (stats.other_total > 0) {
      slices.push({ label: this.otherLabel, value: stats.other_total });
    }

    return slices;
  });

  readonly hasCharts = computed(() => (this.stats()?.total ?? 0) > 0);

  /** Which row has its before/after opened. One at a time: a page with every row
      expanded is the wall of cards this table replaced. */
  readonly expanded = signal<number | null>(null);

  toggle(id: number): void {
    this.expanded.update((open) => (open === id ? null : id));
  }

  /**
   * The stored action in words.
   *
   * Falls back to the identifier rather than to a blank, so an action added to the
   * backend is still readable the first time it happens — an audit trail with a gap
   * in it is worse than one with an untranslated line in it.
   */
  actionLabel(action: string): string {
    return this.translated(`auditActions.${action}`, action);
  }

  /** The short class name the API sends, in words. */
  entityLabel(entity: string): string {
    return this.translated(`auditEntities.${entity}`, entity);
  }

  /**
   * Which of the four state tints an action wears.
   *
   * Grouped by what the action did, not by the word before its dot: a reader wants
   * to find the refusals and the erasures, and those live under six different
   * prefixes. Read-only events stay teal so that opening a record never looks like
   * changing one.
   */
  actionTone(action: string): string {
    const verb = action.split('.')[1] ?? '';

    if (/(rejected|suspended|cancelled|failed|blocked|anonymized|removed)$/.test(verb)) {
      return 'clp-tint-red';
    }

    if (/(created|registered|recorded|activated|issued|accepted|applied|verified)$/.test(verb)) {
      return 'clp-tint-green';
    }

    if (/(updated|changed|assigned|adjusted|requested|resent|exported|submitted)$/.test(verb)) {
      return 'clp-tint-amber';
    }

    return 'clp-tint-teal';
  }

  private translated(key: string, fallback: string): string {
    const label = this.translate.instant(key);

    return label === key || label === '' ? fallback : label;
  }

  /** Translated on read rather than captured once, so switching language relabels
      the slice instead of leaving last language's word in the legend. */
  private get otherLabel(): string {
    return this.translate.instant('audit.otherActions');
  }

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

    const raw = this.filters();

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
    this.form.reset({ action: '', from: null, to: null });
    this.expanded.set(null);
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
