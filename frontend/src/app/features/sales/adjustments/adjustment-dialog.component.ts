import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatRadioModule } from '@angular/material/radio';
import { MatSelectModule } from '@angular/material/select';
import { TranslateModule } from '@ngx-translate/core';

import { AdjustmentForm, CustomerCard } from '../../../core/models/sales.model';
import { Branch } from '../../../core/models/staff.model';
import { AuthService } from '../../../core/services/auth.service';
import { StaffService } from '../../../core/services/staff.service';

export interface AdjustmentDialogData {
  customer: CustomerCard;
}

/**
 * Correcting a balance by hand (BRD 7.2, ledger.adjust).
 *
 * Direction is a choice rather than a minus sign, because a mistyped sign on a
 * balance is a mistake nobody notices until the customer does. The amount stays
 * positive in the field and the direction is applied on the way out.
 */
@Component({
  selector: 'app-adjustment-dialog',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatRadioModule,
    MatSelectModule,
  ],
  templateUrl: './adjustment-dialog.component.html',
})
export class AdjustmentDialogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly staff = inject(StaffService);
  private readonly auth = inject(AuthService);
  private readonly dialogRef = inject(
    MatDialogRef<AdjustmentDialogComponent, AdjustmentForm | undefined>
  );

  readonly data = inject<AdjustmentDialogData>(MAT_DIALOG_DATA);

  readonly branches = signal<Branch[]>([]);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  /** An owner belongs to no branch, so they say where the correction belongs. */
  readonly needsBranch = computed(() => this.auth.user()?.branch_id === null);

  readonly balance = computed(() => Number(this.data.customer.cycle?.total_amount ?? 0));

  readonly form = this.fb.nonNullable.group({
    direction: ['add' as 'add' | 'deduct'],
    amount: [null as number | null, [Validators.required, Validators.min(0.01)]],
    reason: ['', [Validators.required, Validators.minLength(10), Validators.maxLength(1000)]],
    branch_id: [null as number | null],
  });

  private readonly direction = signal<'add' | 'deduct'>('add');

  readonly isDeduction = computed(() => this.direction() === 'deduct');

  constructor() {
    this.form.controls.direction.valueChanges.subscribe((value) => this.direction.set(value));

    if (this.needsBranch()) {
      this.staff.branches().subscribe({
        next: (branches) => this.branches.set(branches.filter((b) => b.is_active)),
        error: () => this.branches.set([]),
      });
    }
  }

  submit(): void {
    const amount = this.form.controls.amount.value;

    if (this.form.invalid || !amount) {
      this.form.markAllAsTouched();
      return;
    }

    // The server checks this too; catching it here saves the owner a round trip
    // only to be told the balance is smaller than the deduction.
    if (this.isDeduction() && amount > this.balance()) {
      this.form.controls.amount.markAsTouched();
      this.form.controls.amount.setErrors({ tooMuch: true });
      return;
    }

    if (this.needsBranch() && !this.form.controls.branch_id.value) {
      this.form.controls.branch_id.markAsTouched();
      this.form.controls.branch_id.setErrors({ required: true });
      return;
    }

    this.dialogRef.close({
      amount: this.isDeduction() ? -amount : amount,
      reason: this.form.controls.reason.value.trim(),
      branch_id: this.form.controls.branch_id.value,
    });
  }
}
