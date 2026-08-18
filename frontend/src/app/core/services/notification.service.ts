import { Injectable, inject } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslateService } from '@ngx-translate/core';

@Injectable({ providedIn: 'root' })
export class NotificationService {
  private readonly snackBar = inject(MatSnackBar);
  private readonly translate = inject(TranslateService);

  success(message: string): void {
    this.show(message, 'clp-snack-success', 4000);
  }

  error(message: string): void {
    this.show(message, 'clp-snack-error', 7000);
  }

  /** Messages coming from the API are already localised; keys are translated. */
  private show(message: string, panelClass: string, duration: number): void {
    const text = this.translate.instant(message);

    this.snackBar.open(text, this.translate.instant('common.dismiss'), {
      duration,
      panelClass: [panelClass],
      horizontalPosition: 'center',
      verticalPosition: 'top',
    });
  }
}
