import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { TranslateModule } from '@ngx-translate/core';

import { FraudSignal, ReportPeriod } from '../../core/models/report.model';
import { AuthService } from '../../core/services/auth.service';
import { ReportService } from '../../core/services/report.service';

/**
 * The anti-fraud signals of BRD 12.
 *
 * Deliberately restrained. Every pattern here has an innocent explanation, so the
 * screen states what it saw and stops: no red banners, no accusations, no counter of
 * "threats". The wording matters as much as the query — a shop owner reading that a
 * named employee is "suspicious" will act on it, and the system does not know that.
 *
 * An empty screen is the expected result, so it says so plainly rather than looking
 * broken.
 */
@Component({
  selector: 'app-alerts',
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
  ],
  templateUrl: './alerts.component.html',
})
export class AlertsComponent {
  private readonly fb = inject(FormBuilder);
  private readonly reports = inject(ReportService);
  private readonly auth = inject(AuthService);

  readonly loading = signal(true);
  readonly signals = signal<FraudSignal[]>([]);
  readonly period = signal<ReportPeriod | null>(null);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  readonly highCount = computed(
    () => this.signals().filter((signal) => signal.severity === 'high').length
  );

  readonly form = this.fb.nonNullable.group({
    from: [this.startOfMonth()],
    to: [this.today()],
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.reports.signals(this.form.getRawValue()).subscribe({
      next: (report) => {
        this.signals.set(report.data);
        this.period.set(report.period);
        this.loading.set(false);
      },
      error: () => {
        this.signals.set([]);
        this.loading.set(false);
      },
    });
  }

  /** Icons carry the meaning faster than the text does when scanning. */
  icon(type: string): string {
    switch (type) {
      case 'out_of_hours':
        return 'bedtime';
      case 'frequent_corrections':
        return 'undo';
      case 'backdated':
        return 'event_busy';
      case 'repeated_redemptions':
        return 'redeem';
      default:
        return 'group';
    }
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
}
