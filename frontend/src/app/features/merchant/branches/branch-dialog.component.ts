import { Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslateModule } from '@ngx-translate/core';

import { Branch, BranchForm } from '../../../core/models/staff.model';

/**
 * Add or edit a branch (BRD 8.2 step 1). Server-side validation errors land on
 * the fields through the caller, so nothing is duplicated here.
 */
@Component({
  selector: 'app-branch-dialog',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
  ],
  templateUrl: './branch-dialog.component.html',
})
export class BranchDialogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly dialogRef = inject(MatDialogRef<BranchDialogComponent, BranchForm>);

  readonly branch = inject<Branch | null>(MAT_DIALOG_DATA);

  readonly form = this.fb.nonNullable.group({
    name: [this.branch?.name ?? '', [Validators.required, Validators.maxLength(255)]],
    city: [this.branch?.city ?? '', [Validators.required, Validators.maxLength(100)]],
    address: [this.branch?.address ?? ''],
    phone: [this.branch?.phone ?? '', [Validators.pattern(/^$|^\+?[\d\s-]{6,20}$/)]],
  });

  save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();

    this.dialogRef.close({
      name: raw.name,
      city: raw.city,
      address: raw.address.trim() || null,
      phone: raw.phone.trim() || null,
    });
  }
}
