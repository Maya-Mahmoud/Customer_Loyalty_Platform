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
 * The signed-in user's own account: their picture, their name, their password.
 *
 * Separate from the store settings on purpose. Everyone has an account and reaches
 * this screen; only the owner has a store to configure. Putting both on one page
 * would show a sales rep a heading they can never act on.
 *
 * Three independent forms rather than one: a failed password change should not throw
 * away a name just typed, and changing a picture should not require filling anything
 * in. Each saves on its own and reports on its own.
 */
@Component({
  selector: 'app-profile',
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
  templateUrl: './profile.component.html',
})
export class ProfileComponent {
  private readonly fb = inject(FormBuilder);
  private readonly profiles = inject(ProfileService);
  private readonly auth = inject(AuthService);
  private readonly notifications = inject(NotificationService);

  readonly user = this.auth.user;

  readonly savingProfile = signal(false);
  readonly savingPassword = signal(false);
  readonly uploading = signal(false);

  readonly roleLabelKey = computed(() => 'roles.' + (this.user()?.role ?? 'sales_rep'));

  /** Two letters when there is no picture, which beats a grey silhouette. */
  readonly initials = computed(() => {
    const name = this.user()?.name ?? '';

    return name
      .split(' ')
      .filter((part) => part.length > 0)
      .slice(0, 2)
      .map((part) => part[0])
      .join('');
  });

  readonly profileForm = this.fb.nonNullable.group({
    name: [this.user()?.name ?? '', [Validators.required, Validators.maxLength(255)]],
    phone: [this.user()?.phone ?? '', [Validators.pattern(/^$|^\+?[\d\s-]{8,20}$/)]],
  });

  readonly passwordForm = this.fb.nonNullable.group({
    current_password: ['', [Validators.required]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', [Validators.required]],
  });

  saveProfile(): void {
    if (this.profileForm.invalid || this.savingProfile()) {
      this.profileForm.markAllAsTouched();
      return;
    }

    this.savingProfile.set(true);
    clearServerErrors(this.profileForm);

    this.profiles.updateProfile(this.profileForm.getRawValue()).subscribe({
      next: () => {
        this.notifications.success('profile.detailsSaved');
        this.savingProfile.set(false);
      },
      error: (error) => {
        applyServerErrors(this.profileForm, error);
        this.savingProfile.set(false);
      },
    });
  }

  savePassword(): void {
    const { password, password_confirmation: confirmation } = this.passwordForm.getRawValue();

    if (this.passwordForm.invalid || this.savingPassword()) {
      this.passwordForm.markAllAsTouched();
      return;
    }

    if (password !== confirmation) {
      this.passwordForm.controls.password_confirmation.setErrors({ mismatch: true });
      return;
    }

    this.savingPassword.set(true);
    clearServerErrors(this.passwordForm);

    this.profiles.changePassword(this.passwordForm.getRawValue()).subscribe({
      next: (result) => {
        this.notifications.success(result.message);
        // Nothing typed here is worth keeping once it has been used.
        this.passwordForm.reset();
        this.savingPassword.set(false);
      },
      error: (error) => {
        applyServerErrors(this.passwordForm, error);
        this.savingPassword.set(false);
      },
    });
  }

  pickAvatar(event: Event): void {
    const file = this.fileFrom(event);

    if (file === null) {
      return;
    }

    this.uploading.set(true);

    this.profiles.uploadAvatar(file).subscribe({
      next: () => {
        this.notifications.success('profile.pictureSaved');
        this.uploading.set(false);
      },
      error: () => this.uploading.set(false),
    });
  }

  removeAvatar(): void {
    this.uploading.set(true);

    this.profiles.removeAvatar().subscribe({
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
