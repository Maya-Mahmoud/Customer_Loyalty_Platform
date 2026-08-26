import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { TranslateModule } from '@ngx-translate/core';

import { applyServerErrors, clearServerErrors } from '../../core/forms/server-errors';
import { AuthService } from '../../core/services/auth.service';
import { NotificationService } from '../../core/services/notification.service';
import { ProfileService } from '../../core/services/profile.service';

/**
 * The store's own settings (BRD FR-MER-05, FR-MER-06).
 *
 * The store, not the account — a user's own name, picture and password live on the
 * profile screen. Keeping them apart matters because the audiences differ: everyone
 * has an account, and only the owner has a store to configure.
 *
 * The registered name and the commercial register are shown and not editable. They
 * identify the business and were verified at registration (BRD 8.1); a screen that
 * let an owner rewrite them would make that verification decorative.
 */
@Component({
  selector: 'app-settings',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressSpinnerModule,
  ],
  templateUrl: './settings.component.html',
})
export class SettingsComponent {
  private readonly fb = inject(FormBuilder);
  private readonly profiles = inject(ProfileService);
  private readonly auth = inject(AuthService);
  private readonly notifications = inject(NotificationService);

  readonly merchant = this.auth.merchant;

  readonly saving = signal(false);
  readonly uploading = signal(false);
  readonly loading = signal(true);

  readonly currency = computed(() => this.merchant()?.currency ?? 'USD');

  readonly form = this.fb.nonNullable.group({
    trade_name: [''],
    city: ['', [Validators.required, Validators.maxLength(120)]],
    phone: ['', [Validators.required, Validators.pattern(/^\+?[\d\s-]{8,32}$/)]],
    currency: ['USD', [Validators.required, Validators.pattern(/^[A-Za-z]{3}$/)]],
  });

  constructor() {
    /*
     * The phone is not part of the session profile the login response carries, so
     * the store is read once to fill the form. Without it an owner saving their city
     * would blank a number they never saw.
     */
    this.profiles.store().subscribe({
      next: (store) => {
        this.form.patchValue({
          trade_name: store.trade_name ?? '',
          city: store.city,
          phone: (store as { phone?: string }).phone ?? '',
          currency: store.currency,
        });
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  save(): void {
    if (this.form.invalid || this.saving()) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    clearServerErrors(this.form);

    const raw = this.form.getRawValue();

    this.profiles
      .updateStore({
        trade_name: raw.trade_name === '' ? null : raw.trade_name,
        city: raw.city,
        phone: raw.phone,
        currency: raw.currency.toUpperCase(),
      })
      .subscribe({
        next: () => {
          this.notifications.success('settings.storeSaved');
          this.saving.set(false);
        },
        error: (error) => {
          applyServerErrors(this.form, error);
          this.saving.set(false);
        },
      });
  }

  pickLogo(event: Event): void {
    const file = this.fileFrom(event);

    if (file === null) {
      return;
    }

    this.uploading.set(true);

    this.profiles.uploadLogo(file).subscribe({
      next: () => {
        this.notifications.success('settings.logoSaved');
        this.uploading.set(false);
      },
      error: () => this.uploading.set(false),
    });
  }

  removeLogo(): void {
    this.uploading.set(true);

    this.profiles.removeLogo().subscribe({
      next: () => this.uploading.set(false),
      error: () => this.uploading.set(false),
    });
  }

  /**
   * Reads the chosen file and clears the input, so picking the same file twice in a
   * row still fires a change event.
   */
  private fileFrom(event: Event): File | null {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    input.value = '';

    return file;
  }
}
