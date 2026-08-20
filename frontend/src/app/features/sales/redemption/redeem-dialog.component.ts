import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { TranslateModule } from '@ngx-translate/core';

import { Branch } from '../../../core/models/staff.model';
import {
  CustomerCard,
  RedeemForm,
  RedemptionPreview,
} from '../../../core/models/sales.model';
import { AuthService } from '../../../core/services/auth.service';
import { SalesService } from '../../../core/services/sales.service';
import { StaffService } from '../../../core/services/staff.service';

export interface RedeemDialogData {
  customer: CustomerCard;
}

/**
 * Confirming a reward before it is paid (BRD 8.6).
 *
 * The dialog fetches the figure rather than computing it, so what the manager
 * authorises is the same number the server will pay — the screen never does the
 * arithmetic itself. Nothing moves until confirm is pressed.
 *
 * The exception path of BR-014 lives here too, but only for an owner: a branch
 * manager cannot approve their own exception, so they are not shown a switch that
 * the server would refuse.
 */
@Component({
  selector: 'app-redeem-dialog',
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
    MatProgressSpinnerModule,
    MatSelectModule,
  ],
  templateUrl: './redeem-dialog.component.html',
})
export class RedeemDialogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly sales = inject(SalesService);
  private readonly staff = inject(StaffService);
  private readonly auth = inject(AuthService);
  private readonly dialogRef = inject(MatDialogRef<RedeemDialogComponent, RedeemForm | undefined>);

  readonly data = inject<RedeemDialogData>(MAT_DIALOG_DATA);

  readonly loading = signal(true);
  readonly preview = signal<RedemptionPreview | null>(null);
  readonly branches = signal<Branch[]>([]);

  /** Null while loading, and whenever the customer has not qualified. */
  readonly reward = computed(() => this.preview()?.reward ?? null);
  readonly cycle = computed(() => this.preview()?.cycle ?? null);

  readonly currency = computed(() => this.auth.merchant()?.currency ?? 'USD');

  /** Only the owner may authorise an exception (BR-014). */
  readonly canOverride = computed(() => this.auth.role() === 'merchant_owner');

  /** An owner belongs to no branch, so they say where the reward is handed over. */
  readonly needsBranch = computed(() => this.auth.user()?.branch_id === null);

  readonly form = this.fb.nonNullable.group({
    override: [false],
    override_reason: ['', [Validators.maxLength(1000)]],
    branch_id: [null as number | null],
  });

  private readonly overriding = signal(false);

  /**
   * Either the customer qualifies, or the owner is deliberately overriding. A
   * manager looking at an ineligible customer has nothing to confirm.
   */
  readonly canConfirm = computed(() => {
    const preview = this.preview();

    if (preview === null) {
      return false;
    }

    return preview.eligible || (this.canOverride() && this.overriding());
  });

  constructor() {
    this.form.controls.override.valueChanges.subscribe((on) => this.overriding.set(on));

    if (this.needsBranch()) {
      this.staff.branches().subscribe({
        next: (branches) => this.branches.set(branches.filter((b) => b.is_active)),
        error: () => this.branches.set([]),
      });
    }

    this.sales.previewRedemption(this.data.customer.id).subscribe({
      next: (preview) => {
        this.preview.set(preview);
        this.loading.set(false);
      },
      error: () => {
        this.preview.set({ eligible: false, reward: null });
        this.loading.set(false);
      },
    });
  }

  confirm(): void {
    const override = this.form.controls.override.value;
    const reason = this.form.controls.override_reason.value.trim();
    const branchId = this.form.controls.branch_id.value;

    // BR-014 keeps the reason with the exception, so the reason is not optional
    // once the switch is on.
    if (override && reason.length < 5) {
      this.form.controls.override_reason.markAsTouched();
      this.form.controls.override_reason.setErrors({ required: true });
      return;
    }

    if (this.needsBranch() && !branchId) {
      this.form.controls.branch_id.markAsTouched();
      this.form.controls.branch_id.setErrors({ required: true });
      return;
    }

    this.dialogRef.close({
      override,
      override_reason: override ? reason : null,
      branch_id: branchId,
    });
  }
}
