import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { Router, RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { interval } from 'rxjs';

import { applyServerErrors, clearServerErrors } from '../../../core/forms/server-errors';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';
import { NotificationService } from '../../../core/services/notification.service';
import { PasswordResetService } from '../../../core/services/password-reset.service';

/** Seconds before another code may be requested; matches the server cooldown. */
const RESEND_COOLDOWN_SECONDS = 60;

/**
 * Recovering a forgotten password, start to finish on one screen.
 *
 * The user asks for a code, then types the code and the new password together in
 * the same form. No link to follow, so it works when the mailbox is on a phone
 * while the browser is on a laptop — and there is only ever one page to be on.
 */
@Component({
  selector: 'app-forgot-password',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressSpinnerModule,
  ],
  templateUrl: './forgot-password.component.html',
})
export class ForgotPasswordComponent {
  private readonly fb = inject(FormBuilder);
  private readonly passwords = inject(PasswordResetService);
  private readonly notifications = inject(NotificationService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly language = inject(LanguageService);
  private readonly destroyRef = inject(DestroyRef);

  readonly currentLanguage = this.language.current;

  /** 'request' asks for the code, 'reset' takes the code and the new password. */
  readonly step = signal<'request' | 'reset'>('request');
  readonly loading = signal(false);
  readonly formError = signal<string | null>(null);
  readonly hidePassword = signal(true);
  readonly codeTtlMinutes = signal(10);
  readonly resendCooldown = signal(0);

  readonly emailForm = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
  });

  readonly resetForm = this.fb.nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
    // Mirrors the server policy of BRD FR-SEC-03, so the rules show before
    // submitting rather than only in the response.
    password: [
      '',
      [
        Validators.required,
        Validators.minLength(10),
        Validators.pattern(/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/),
      ],
    ],
    password_confirmation: ['', [Validators.required]],
  });

  constructor() {
    interval(1000)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.resendCooldown.update((seconds) => Math.max(0, seconds - 1)));
  }

  toggleLanguage(): void {
    this.language.toggle();
  }

  get email(): string {
    return this.emailForm.controls.email.value;
  }

  get passwordsMismatch(): boolean {
    const { password, password_confirmation } = this.resetForm.getRawValue();

    return password_confirmation.length > 0 && password !== password_confirmation;
  }

  requestCode(): void {
    if (this.emailForm.invalid || this.loading()) {
      this.emailForm.markAllAsTouched();
      return;
    }

    this.start();

    this.passwords.request(this.email).subscribe({
      next: (response) => {
        this.loading.set(false);
        this.codeTtlMinutes.set(response.expires_in_minutes);
        this.resendCooldown.set(RESEND_COOLDOWN_SECONDS);
        this.step.set('reset');
      },
      error: (response: HttpErrorResponse) => this.fail(this.emailForm, response),
    });
  }

  resend(): void {
    this.passwords.request(this.email).subscribe({
      next: (response) => {
        this.notifications.success(response.message);
        this.resendCooldown.set(RESEND_COOLDOWN_SECONDS);
      },
      error: (response: HttpErrorResponse) => this.fail(this.resetForm, response),
    });
  }

  submitReset(): void {
    if (this.resetForm.invalid || this.passwordsMismatch || this.loading()) {
      this.resetForm.markAllAsTouched();
      return;
    }

    this.start();

    const { code, password, password_confirmation } = this.resetForm.getRawValue();

    this.passwords.reset(this.email, code, password, password_confirmation).subscribe({
      next: (response) => {
        this.notifications.success(response.message);

        // The response already carries a working token, so the user lands inside
        // rather than being sent back to the login form.
        this.auth.restore().subscribe(() => {
          void this.router.navigateByUrl(this.auth.homeRoute());
        });
      },
      error: (response: HttpErrorResponse) => this.fail(this.resetForm, response),
    });
  }

  backToEmail(): void {
    this.step.set('request');
    this.resetForm.reset();
  }

  private start(): void {
    this.loading.set(true);
    this.formError.set(null);
    clearServerErrors(this.emailForm);
    clearServerErrors(this.resetForm);
  }

  private fail(
    form: typeof this.emailForm | typeof this.resetForm,
    response: HttpErrorResponse
  ): void {
    this.loading.set(false);
    this.formError.set(applyServerErrors(form, response)[0] ?? null);
  }
}
