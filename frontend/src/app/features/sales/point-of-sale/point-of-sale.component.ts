import { HttpErrorResponse } from '@angular/common/http';
import { Component, ElementRef, computed, inject, signal, viewChild } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatDividerModule } from '@angular/material/divider';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { TranslateModule } from '@ngx-translate/core';

import { applyServerErrors, clearServerErrors } from '../../../core/forms/server-errors';
import { CustomerCard, CycleState } from '../../../core/models/sales.model';
import { Branch } from '../../../core/models/staff.model';
import { AuthService } from '../../../core/services/auth.service';
import { SalesService } from '../../../core/services/sales.service';
import { StaffService } from '../../../core/services/staff.service';

/**
 * The till (BRD 8.4) — the screen used more than every other one combined.
 *
 * BRD NFR-05 allows thirty seconds for a whole entry with a customer waiting, and
 * NFR-06 says it has to work on a phone. Everything here follows from that: one
 * screen rather than a wizard, the phone field focused on arrival, Enter moving
 * forward, the date pre-filled with today, and the focus jumping to the invoice
 * number the moment a customer resolves.
 *
 * The eligibility banner is deliberately loud. FR-RED-02 wants the rep told the
 * instant a customer qualifies, and a quiet line would be missed at a busy counter.
 */
@Component({
  selector: 'app-point-of-sale',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatCheckboxModule,
    MatDividerModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatProgressSpinnerModule,
    MatSelectModule,
  ],
  templateUrl: './point-of-sale.component.html',
})
export class PointOfSaleComponent {
  private readonly fb = inject(FormBuilder);
  private readonly sales = inject(SalesService);
  private readonly staff = inject(StaffService);
  private readonly auth = inject(AuthService);

  private readonly phoneInput = viewChild<ElementRef<HTMLInputElement>>('phoneField');
  private readonly invoiceInput = viewChild<ElementRef<HTMLInputElement>>('invoiceField');

  /** 'lookup' searches, 'register' captures a new customer, 'sale' records it. */
  readonly stage = signal<'lookup' | 'register' | 'sale'>('lookup');

  readonly searching = signal(false);
  readonly saving = signal(false);
  readonly formError = signal<string | null>(null);

  readonly customer = signal<CustomerCard | null>(null);
  /** True for the BR-022 case: a customer who will not give a number. */
  readonly anonymous = signal(false);

  /** What the last save produced, kept on screen while the rep talks. */
  readonly lastResult = signal<{ counted: boolean; cycle: CycleState | null; message: string } | null>(null);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  /** An owner belongs to no branch, so they have to say which one they are at. */
  readonly needsBranch = computed(() => this.auth.user()?.branch_id === null);
  readonly branches = signal<Branch[]>([]);

  readonly phoneForm = this.fb.nonNullable.group({
    phone: ['', [Validators.required, Validators.pattern(/^\+?[\d\s-]{6,20}$/)]],
  });

  readonly registerForm = this.fb.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    // BRD FR-CUS-07: the rep records the spoken agreement on the customer's behalf.
    consent_given: [true],
  });

  readonly saleForm = this.fb.nonNullable.group({
    invoice_number: ['', [Validators.required, Validators.maxLength(64)]],
    amount: [null as number | null, [Validators.required, Validators.min(0.01)]],
    invoice_date: [this.today(), [Validators.required]],
    branch_id: [null as number | null],
  });

  /** Shown under the amount field so a rep knows before saving, not after. */
  readonly belowMinimum = computed(() => {
    const min = Number(this.customer()?.cycle?.min_invoice_amount ?? 0);
    const amount = Number(this.saleForm.controls.amount.value ?? 0);

    return min > 0 && amount > 0 && amount < min;
  });

  constructor() {
    if (this.needsBranch()) {
      this.staff.branches().subscribe({
        next: (branches) => this.branches.set(branches.filter((b) => b.is_active)),
        error: () => this.branches.set([]),
      });
    }
  }

  // -----------------------------------------------------------------
  // Lookup
  // -----------------------------------------------------------------

  search(): void {
    if (this.phoneForm.invalid || this.searching()) {
      this.phoneForm.markAllAsTouched();
      return;
    }

    this.searching.set(true);
    this.formError.set(null);
    this.lastResult.set(null);

    this.sales.lookup(this.phoneForm.controls.phone.value).subscribe({
      next: (result) => {
        this.searching.set(false);

        if (result.found && result.customer) {
          this.customer.set(result.customer);
          this.stage.set('sale');
          this.focusInvoice();
        } else {
          // Not found is how the rep learns to offer registration.
          this.customer.set(null);
          this.stage.set('register');
        }
      },
      error: (response: HttpErrorResponse) => {
        this.searching.set(false);
        this.formError.set(applyServerErrors(this.phoneForm, response)[0] ?? null);
      },
    });
  }

  /** BRD BR-022: the sale is still recorded, it simply belongs to nobody. */
  skipCustomer(): void {
    this.customer.set(null);
    this.anonymous.set(true);
    this.stage.set('sale');
    this.focusInvoice();
  }

  // -----------------------------------------------------------------
  // Registering on the spot
  // -----------------------------------------------------------------

  register(): void {
    if (this.registerForm.invalid || this.saving()) {
      this.registerForm.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    this.formError.set(null);
    clearServerErrors(this.registerForm);

    this.sales
      .register({
        phone: this.phoneForm.controls.phone.value,
        name: this.registerForm.controls.name.value,
        consent_given: this.registerForm.controls.consent_given.value,
      })
      .subscribe({
        next: (customer) => {
          this.saving.set(false);
          this.customer.set(customer);
          this.stage.set('sale');
          this.focusInvoice();
        },
        error: (response: HttpErrorResponse) => {
          this.saving.set(false);

          // AF-04 refusals land on the phone, which is on the previous step, so
          // they are surfaced at form level where the rep can actually see them.
          const unmatched = applyServerErrors(this.registerForm, response);
          const body = response.error as { errors?: Record<string, string[]> } | null;

          this.formError.set(unmatched[0] ?? body?.errors?.['phone']?.[0] ?? null);
        },
      });
  }

  // -----------------------------------------------------------------
  // Recording the sale
  // -----------------------------------------------------------------

  recordSale(): void {
    if (this.saleForm.invalid || this.saving()) {
      this.saleForm.markAllAsTouched();
      return;
    }

    if (this.needsBranch() && !this.saleForm.controls.branch_id.value) {
      this.saleForm.controls.branch_id.markAsTouched();
      return;
    }

    this.saving.set(true);
    this.formError.set(null);
    clearServerErrors(this.saleForm);

    const raw = this.saleForm.getRawValue();

    this.sales
      .recordSale({
        invoice_number: raw.invoice_number,
        amount: Number(raw.amount),
        invoice_date: raw.invoice_date,
        customer_id: this.customer()?.id ?? null,
        branch_id: this.needsBranch() ? raw.branch_id : null,
      })
      .subscribe({
        next: (result) => {
          this.saving.set(false);
          this.lastResult.set({
            counted: result.counted,
            cycle: result.cycle,
            message: result.message,
          });

          // Ready for the next customer, with the outcome of this one still on
          // screen so the rep can read it out.
          this.resetForNext();
        },
        error: (response: HttpErrorResponse) => {
          this.saving.set(false);
          this.formError.set(applyServerErrors(this.saleForm, response)[0] ?? null);
        },
      });
  }

  /** Back to the phone field, keeping the last outcome visible. */
  private resetForNext(): void {
    this.customer.set(null);
    this.anonymous.set(false);
    this.stage.set('lookup');

    this.phoneForm.reset();
    this.registerForm.reset({ consent_given: true });
    this.saleForm.reset({ invoice_date: this.today(), branch_id: null });

    this.focusPhone();
  }

  /** Abandons the current customer without recording anything. */
  startOver(): void {
    this.lastResult.set(null);
    this.resetForNext();
  }

  private focusPhone(): void {
    setTimeout(() => this.phoneInput()?.nativeElement.focus());
  }

  private focusInvoice(): void {
    setTimeout(() => this.invoiceInput()?.nativeElement.focus());
  }

  private today(): string {
    const now = new Date();

    return [
      now.getFullYear(),
      String(now.getMonth() + 1).padStart(2, '0'),
      String(now.getDate()).padStart(2, '0'),
    ].join('-');
  }
}
