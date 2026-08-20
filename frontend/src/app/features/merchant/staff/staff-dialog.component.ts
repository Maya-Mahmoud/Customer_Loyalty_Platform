import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslateModule } from '@ngx-translate/core';

import { UserRole } from '../../../core/models/auth.model';
import { Branch, StaffForm, StaffMember } from '../../../core/models/staff.model';

export interface StaffDialogData {
  member: StaffMember | null;
  branches: Branch[];
}

/**
 * Add or edit a staff account (BRD 8.2 step 2).
 *
 * There is no password field. The user chooses their own through the invitation
 * (BRD FR-BRN-04), so nobody ever knows anyone else's.
 */
@Component({
  selector: 'app-staff-dialog',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
  ],
  templateUrl: './staff-dialog.component.html',
})
export class StaffDialogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly dialogRef = inject(MatDialogRef<StaffDialogComponent, Partial<StaffForm>>);

  readonly data = inject<StaffDialogData>(MAT_DIALOG_DATA);

  /** A store owner may hand out these three; never the platform supervisor. */
  readonly roles: UserRole[] = ['merchant_owner', 'branch_manager', 'sales_rep'];

  readonly isEdit = this.data.member !== null;
  readonly activeBranches = this.data.branches.filter((b) => b.is_active);

  readonly form = this.fb.nonNullable.group({
    name: [this.data.member?.name ?? '', [Validators.required, Validators.maxLength(255)]],
    email: [this.data.member?.email ?? '', [Validators.required, Validators.email]],
    phone: [this.data.member?.phone ?? '', [Validators.pattern(/^$|^\+?[\d\s-]{8,20}$/)]],
    role: [this.data.member?.role ?? ('sales_rep' as UserRole), [Validators.required]],
    branch_id: [this.data.member?.branch_id ?? null as number | null],
  });

  private readonly role = signal<UserRole>(this.form.controls.role.value);

  /** An owner spans every branch, so the field is only shown when it applies. */
  readonly needsBranch = computed(
    () => this.role() === 'branch_manager' || this.role() === 'sales_rep'
  );

  constructor() {
    this.form.controls.role.valueChanges.subscribe((role) => this.role.set(role));

    if (this.isEdit) {
      // The email identifies the account and already received an invitation;
      // changing it belongs to a verified flow of its own.
      this.form.controls.email.disable();
    }
  }

  save(): void {
    if (this.form.invalid || (this.needsBranch() && !this.form.controls.branch_id.value)) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();

    const payload: Partial<StaffForm> = {
      name: raw.name,
      phone: raw.phone.trim() || null,
      role: raw.role,
      branch_id: this.needsBranch() ? raw.branch_id : null,
    };

    if (!this.isEdit) {
      payload.email = raw.email;
    }

    this.dialogRef.close(payload);
  }
}
