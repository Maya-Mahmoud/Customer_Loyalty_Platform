import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTableModule } from '@angular/material/table';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';

import { CustomerCard, Redemption } from '../../../core/models/sales.model';
import { AuthService } from '../../../core/services/auth.service';
import { NotificationService } from '../../../core/services/notification.service';
import { SalesService } from '../../../core/services/sales.service';
import { RedeemDialogComponent } from '../redemption/redeem-dialog.component';

/**
 * Customer lookup (BRD 8.5) — reading a customer's position without recording a
 * sale, so a rep can answer how close they are before or during a purchase.
 *
 * Read-only by design, and there is no list. BRD BR-019 forbids a rep exporting or
 * printing customer lists, so the screen only ever shows one customer, reached by
 * knowing their number. The customer base is the merchant's asset and the screen
 * reflects that.
 */
@Component({
  selector: 'app-customer-lookup',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatProgressSpinnerModule,
    MatTableModule,
  ],
  templateUrl: './customer-lookup.component.html',
})
export class CustomerLookupComponent {
  private readonly fb = inject(FormBuilder);
  private readonly sales = inject(SalesService);
  private readonly auth = inject(AuthService);
  private readonly notifications = inject(NotificationService);
  private readonly dialog = inject(MatDialog);

  readonly invoiceColumns = ['number', 'amount', 'date', 'counted'];
  readonly rewardColumns = ['date', 'amount', 'cycle', 'by'];

  readonly searching = signal(false);
  readonly searched = signal(false);
  readonly customer = signal<CustomerCard | null>(null);
  readonly rewards = signal<Redemption[]>([]);
  readonly redeeming = signal(false);
  /** The receipt of the reward just paid, kept on screen until the next search. */
  readonly lastReward = signal<Redemption | null>(null);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');
  readonly canRegister = computed(() => this.auth.has('customers.register'));
  /** BR-013: a rep never sees the button, and the server refuses them anyway. */
  readonly canRedeem = computed(() => this.auth.has('redemptions.create'));

  readonly form = this.fb.nonNullable.group({
    phone: ['', [Validators.required, Validators.pattern(/^\+?[\d\s-]{6,20}$/)]],
  });

  search(): void {
    if (this.form.invalid || this.searching()) {
      this.form.markAllAsTouched();
      return;
    }

    this.searching.set(true);
    this.customer.set(null);
    this.rewards.set([]);
    this.lastReward.set(null);

    this.sales.lookup(this.form.controls.phone.value).subscribe({
      next: (result) => {
        this.searched.set(true);

        if (!result.found || result.customer === null) {
          this.searching.set(false);
          return;
        }

        // Fetched again for the purchase history, which the lookup summary omits
        // to keep the till response small.
        this.sales.card(result.customer.id).subscribe({
          next: (card) => {
            this.customer.set(card);
            this.loadRewards(card.id);
            this.searching.set(false);
          },
          error: () => {
            this.customer.set(result.customer);
            this.searching.set(false);
          },
        });
      },
      error: () => {
        this.searching.set(false);
        this.searched.set(true);
      },
    });
  }

  /**
   * Paying the reward out (BRD 8.6).
   *
   * Two steps on purpose: the dialog shows the figure the server calculated, and
   * only a confirmation there sends the payment. A single button that paid on the
   * first click would be one mis-tap away from giving money away.
   */
  redeem(): void {
    const card = this.customer();

    if (card === null || this.redeeming()) {
      return;
    }

    this.dialog
      .open(RedeemDialogComponent, { data: { customer: card }, width: '520px', autoFocus: false })
      .afterClosed()
      .subscribe((form) => {
        if (!form) {
          return;
        }

        this.redeeming.set(true);

        this.sales.redeem(card.id, form).subscribe({
          next: (result) => {
            this.notifications.success(result.message);
            this.lastReward.set(result.data);
            this.redeeming.set(false);

            // The cycle has closed and a new one opened, so the card on screen is
            // already out of date — it is read again rather than patched.
            this.sales.card(card.id).subscribe({
              next: (fresh) => {
                this.customer.set(fresh);
                this.loadRewards(fresh.id);
              },
            });
          },
          error: () => this.redeeming.set(false),
        });
      });
  }

  /** FR-RED-07: past rewards belong on the card, not in a separate report. */
  private loadRewards(customerId: number): void {
    if (!this.canRedeem()) {
      return;
    }

    this.sales.redemptions(customerId).subscribe({
      next: (rewards) => this.rewards.set(rewards),
      error: () => this.rewards.set([]),
    });
  }
}
