import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatRadioModule } from '@angular/material/radio';
import { TranslateModule } from '@ngx-translate/core';

import { CorrectionForm, CorrectionType, CustomerInvoice } from '../../../core/models/sales.model';
import { AuthService } from '../../../core/services/auth.service';

export interface CorrectionDialogData {
  invoice: CustomerInvoice;
}

/**
 * Asking for an invoice to be cancelled or returned (BRD 8.7).
 *
 * The reason has a floor of ten characters, because it is the whole basis of the
 * manager's decision and the only thing an auditor will have to read a year later.
 * "خطأ" is not a reason.
 */
@Component({
  selector: 'app-correction-dialog',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatRadioModule,
  ],
  templateUrl: './correction-dialog.component.html',
})
export class CorrectionDialogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly dialogRef = inject(
    MatDialogRef<CorrectionDialogComponent, CorrectionForm | undefined>
  );

  readonly data = inject<CorrectionDialogData>(MAT_DIALOG_DATA);

  readonly types: CorrectionType[] = ['cancel', 'full_return', 'partial_return'];

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  /**
   * Whether this request will take effect immediately or wait for a manager. The
   * server decides either way; saying so up front stops a rep expecting the
   * balance to change on the spot (BR-012).
   */
  readonly appliesAtOnce = computed(() => this.auth.has('invoices.amend'));

  readonly form = this.fb.nonNullable.group({
    type: ['cancel' as CorrectionType, [Validators.required]],
    amount: [null as number | null],
    reason: ['', [Validators.required, Validators.minLength(10), Validators.maxLength(1000)]],
  });

  private readonly type = signal<CorrectionType>('cancel');

  readonly isPartial = computed(() => this.type() === 'partial_return');

  constructor() {
    this.form.controls.type.valueChanges.subscribe((type) => this.type.set(type));
  }

  submit(): void {
    const type = this.form.controls.type.value;
    const amount = this.form.controls.amount.value;

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    // A partial return must name an amount, and it has to be smaller than the
    // invoice — returning all of it is a full return (BRD FR-INV-07).
    if (type === 'partial_return') {
      const invoiceAmount = Number(this.data.invoice.amount);

      if (!amount || amount <= 0 || amount >= invoiceAmount) {
        this.form.controls.amount.markAsTouched();
        this.form.controls.amount.setErrors({ range: true });
        return;
      }
    }

    this.dialogRef.close({
      type,
      amount: type === 'partial_return' ? amount : null,
      reason: this.form.controls.reason.value.trim(),
    });
  }
}
