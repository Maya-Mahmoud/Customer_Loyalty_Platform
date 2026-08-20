import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { TranslateModule } from '@ngx-translate/core';

import { InvoiceCorrection } from '../../../core/models/sales.model';
import { AuthService } from '../../../core/services/auth.service';
import { NotificationService } from '../../../core/services/notification.service';
import { SalesService } from '../../../core/services/sales.service';

/**
 * Deciding on correction requests (BRD 8.7, FR-INV-08).
 *
 * One card per request, each showing the invoice and the reason side by side —
 * the decision is a judgement about a specific sale, so the screen puts both in
 * front of the manager rather than making them look the invoice up.
 *
 * A branch manager sees their own branch; the owner sees the whole store. That
 * filtering happens on the server, where it cannot be bypassed.
 */
@Component({
  selector: 'app-correction-queue',
  standalone: true,
  imports: [
    FormsModule,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressSpinnerModule,
  ],
  templateUrl: './correction-queue.component.html',
})
export class CorrectionQueueComponent {
  private readonly sales = inject(SalesService);
  private readonly auth = inject(AuthService);
  private readonly notifications = inject(NotificationService);

  readonly loading = signal(true);
  readonly requests = signal<InvoiceCorrection[]>([]);
  /** The id being decided, so only that card's buttons go quiet. */
  readonly deciding = signal<number | null>(null);

  /** Optional; the reason for a refusal is usually worth writing down. */
  readonly notes = signal<Record<number, string>>({});

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.sales.pendingCorrections().subscribe({
      next: (requests) => {
        this.requests.set(requests);
        this.loading.set(false);
      },
      error: () => {
        this.requests.set([]);
        this.loading.set(false);
      },
    });
  }

  noteFor(id: number): string {
    return this.notes()[id] ?? '';
  }

  setNote(id: number, value: string): void {
    this.notes.update((notes) => ({ ...notes, [id]: value }));
  }

  decide(request: InvoiceCorrection, approve: boolean): void {
    if (this.deciding() !== null) {
      return;
    }

    this.deciding.set(request.id);

    const note = this.noteFor(request.id).trim();

    this.sales.decideCorrection(request.id, approve, note === '' ? null : note).subscribe({
      next: () => {
        this.notifications.success(
          approve ? 'correction.approved' : 'correction.rejected'
        );

        // Taken off the queue rather than re-fetched: the decision is final and
        // the list is short.
        this.requests.update((requests) => requests.filter((r) => r.id !== request.id));
        this.deciding.set(null);
      },
      error: () => this.deciding.set(null),
    });
  }
}
