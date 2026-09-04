import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { TranslateModule } from '@ngx-translate/core';

import { BalanceCard, BalanceStore, BalanceService } from '../../core/services/balance.service';
import { LanguageService } from '../../core/services/language.service';

/**
 * The customer's own balance (BRD FR-CUS-12).
 *
 * Outside the application shell and behind no login, because the customer has no
 * account and never will (BR-001). Everything about this screen assumes a phone held
 * in one hand and a receipt in the other, standing in a shop.
 *
 * The proof is that receipt. It asks for the shop, the number they gave the shop, and
 * the invoice number printed on the receipt — with the date and amount of the last
 * purchase as the fallback for a receipt that was thrown away. A phone number alone
 * would let anybody learn where a person shops.
 */
@Component({
  selector: 'app-balance-lookup',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatProgressSpinnerModule,
    MatSelectModule,
  ],
  templateUrl: './balance-lookup.component.html',
})
export class BalanceLookupComponent {
  private readonly fb = inject(FormBuilder);
  private readonly balances = inject(BalanceService);
  private readonly language = inject(LanguageService);

  readonly stores = signal<BalanceStore[]>([]);
  readonly card = signal<BalanceCard | null>(null);
  readonly loading = signal(false);
  readonly notFound = signal(false);
  readonly currentLanguage = this.language.current;

  /** Shown when the receipt is gone and the customer answers from memory instead. */
  readonly lostReceipt = signal(false);

  readonly form = this.fb.nonNullable.group({
    merchant_id: [null as number | null, [Validators.required]],
    phone: ['', [Validators.required, Validators.pattern(/^\+?[\d\s-]{6,20}$/)]],
    invoice_number: [''],
    invoice_date: [''],
    amount: [null as number | null],
  });

  readonly progressPercent = computed(() => (this.card()?.progress ?? 0) * 100);

  constructor() {
    this.balances.stores().subscribe({
      next: (stores) => this.stores.set(stores),
      error: () => this.stores.set([]),
    });
  }

  submit(): void {
    const raw = this.form.getRawValue();

    /*
     * One of the two proofs has to be there. Checked here as well as on the server so
     * a customer is told before spending one of their few attempts — the endpoint is
     * throttled hard, because invoice numbers run in sequence and a guesser with one
     * receipt would otherwise walk the shop's numbering.
     */
    const hasProof = this.lostReceipt()
      ? raw.invoice_date !== '' && raw.amount !== null
      : raw.invoice_number.trim() !== '';

    if (this.form.invalid || !hasProof) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading.set(true);
    this.notFound.set(false);
    this.card.set(null);

    this.balances
      .lookup({
        merchant_id: raw.merchant_id!,
        phone: raw.phone,
        invoice_number: this.lostReceipt() ? null : raw.invoice_number.trim(),
        invoice_date: this.lostReceipt() ? raw.invoice_date : null,
        amount: this.lostReceipt() ? raw.amount : null,
      })
      .subscribe({
        next: (card) => {
          this.card.set(card);
          this.loading.set(false);
        },
        error: () => {
          // One message for every failure: telling "no such customer" apart from
          // "wrong receipt" would turn this page into a way of discovering who
          // shops where.
          this.notFound.set(true);
          this.loading.set(false);
        },
      });
  }

  startOver(): void {
    this.card.set(null);
    this.notFound.set(false);
    this.form.reset({ merchant_id: null, phone: '', invoice_number: '', invoice_date: '', amount: null });
    this.lostReceipt.set(false);
  }

  toggleLanguage(): void {
    this.language.toggle();
  }
}
