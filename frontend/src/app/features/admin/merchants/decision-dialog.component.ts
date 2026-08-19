import { Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslateModule } from '@ngx-translate/core';

export interface DecisionDialogData {
  titleKey: string;
  bodyKey: string;
  confirmKey: string;
  merchantName: string;
  /** True for reject and suspend, which BRD FR-ADM-02 will not accept without one. */
  requiresReason: boolean;
  destructive?: boolean;
}

export interface DecisionDialogResult {
  reason: string;
}

/**
 * Confirmation step for a status change. The reason field is not optional for
 * rejection or suspension: FR-ADM-02 makes it the record that justifies the
 * decision later in the audit log.
 */
@Component({
  selector: 'app-decision-dialog',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
  ],
  templateUrl: './decision-dialog.component.html',
})
export class DecisionDialogComponent {
  private readonly fb = inject(FormBuilder);
  private readonly dialogRef = inject(MatDialogRef<DecisionDialogComponent, DecisionDialogResult>);

  readonly data = inject<DecisionDialogData>(MAT_DIALOG_DATA);

  readonly form = this.fb.nonNullable.group({
    reason: [
      '',
      this.data.requiresReason
        ? [Validators.required, Validators.minLength(5), Validators.maxLength(1000)]
        : [],
    ],
  });

  confirm(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.dialogRef.close({ reason: this.form.getRawValue().reason.trim() });
  }
}
