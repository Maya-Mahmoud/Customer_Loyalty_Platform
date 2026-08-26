import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslateModule } from '@ngx-translate/core';

import { CustomerCard } from '../../../core/models/sales.model';
import { AuthService } from '../../../core/services/auth.service';

export interface EraseCustomerDialogData {
  customer: CustomerCard;
}

/**
 * Erasing a customer at their request (BRD FR-CUS-10, section 16).
 *
 * Deliberately the most awkward dialog in the application. It cannot be undone, so
 * it asks for the written request and an explicit acknowledgement of what is about
 * to be lost — a confirm button on its own is too easy to press by accident, and
 * this is the one action nobody can walk back.
 */
@Component({
  selector: 'app-erase-customer-dialog',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatCheckboxModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
  ],
  templateUrl: './erase-customer-dialog.component.html',
})
export class EraseCustomerDialogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly dialogRef = inject(
    MatDialogRef<EraseCustomerDialogComponent, string | undefined>
  );

  readonly data = inject<EraseCustomerDialogData>(MAT_DIALOG_DATA);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  /** What the customer loses, shown before rather than after. */
  readonly balance = computed(() => this.data.customer.cycle?.total_amount ?? 0);

  readonly form = this.fb.nonNullable.group({
    reason: ['', [Validators.required, Validators.minLength(10), Validators.maxLength(1000)]],
    acknowledged: [false, [Validators.requiredTrue]],
  });

  readonly submitted = signal(false);

  confirm(): void {
    this.submitted.set(true);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.dialogRef.close(this.form.controls.reason.value.trim());
  }
}
