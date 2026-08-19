import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { interval } from 'rxjs';

import { applyServerErrors, clearServerErrors } from '../../../core/forms/server-errors';
import { LanguageService } from '../../../core/services/language.service';
import { NotificationService } from '../../../core/services/notification.service';
import { RegistrationService } from '../../../core/services/registration.service';

/** Seconds before another code may be requested; matches the server cooldown. */
const RESEND_COOLDOWN_SECONDS = 60;

/**
 * Self-registration, BRD 8.1 steps 1 to 4.
 *
 * Two steps rather than one screen: the applicant fills the form, then proves the
 * email address with a code. Nothing here creates a usable account — activation is
 * the supervisor's call.
 */
@Component({
  selector: 'app-register',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    TranslateModule,
    MatButtonModule,
    MatCardModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressSpinnerModule,
  ],
  templateUrl: './register.component.html',
})
export class RegisterComponent {
  private readonly fb = inject(FormBuilder);
  private readonly registration = inject(RegistrationService);
  private readonly notifications = inject(NotificationService);
  private readonly language = inject(LanguageService);
  private readonly destroyRef = inject(DestroyRef);

  readonly currentLanguage = this.language.current;

  /** 'form' collects the details, 'verify' confirms the code, 'done' waits. */
  readonly step = signal<'form' | 'verify' | 'done'>('form');
  readonly loading = signal(false);
  readonly formError = signal<string | null>(null);

  readonly codeTtlMinutes = signal(10);
  readonly resendCooldown = signal(0);

  readonly detailsForm = this.fb.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    trade_name: [''],
    commercial_register: ['', [Validators.required, Validators.maxLength(100)]],
    owner_name: ['', [Validators.required, Validators.maxLength(255)]],
    email: ['', [Validators.required, Validators.email]],
    phone: ['', [Validators.required, Validators.pattern(/^\+?[\d\s-]{8,20}$/)]],
    city: ['', [Validators.required, Validators.maxLength(100)]],

    // Mirrors the server policy of BRD FR-SEC-03, so the rules are visible before
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

    accepts_terms: [false, [Validators.requiredTrue]],
    accepts_data_processing: [false, [Validators.requiredTrue]],
  });

  readonly hidePassword = signal(true);

  get passwordsMismatch(): boolean {
    const { password, password_confirmation } = this.detailsForm.getRawValue();

    return password_confirmation.length > 0 && password !== password_confirmation;
  }

  readonly codeForm = this.fb.nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
  });

  constructor() {
    // A single ticker drives the cooldown; it stops with the component.
    interval(1000)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.resendCooldown.update((seconds) => Math.max(0, seconds - 1)));
  }

  toggleLanguage(): void {
    this.language.toggle();
  }

  get email(): string {
    return this.detailsForm.controls.email.value;
  }

  submitDetails(): void {
    if (this.detailsForm.invalid || this.passwordsMismatch || this.loading()) {
      this.detailsForm.markAllAsTouched();
      return;
    }

    this.start();

    const raw = this.detailsForm.getRawValue();

    this.registration
      .submit({
        ...raw,
        trade_name: raw.trade_name.trim() || null,
      })
      .subscribe({
        next: (response) => {
          this.loading.set(false);
          this.codeTtlMinutes.set(response.expires_in_minutes);
          this.resendCooldown.set(RESEND_COOLDOWN_SECONDS);
          this.step.set('verify');
        },
        error: (response: HttpErrorResponse) => this.fail(this.detailsForm, response),
      });
  }

  submitCode(): void {
    if (this.codeForm.invalid || this.loading()) {
      this.codeForm.markAllAsTouched();
      return;
    }

    this.start();

    this.registration.verify(this.email, this.codeForm.getRawValue().code).subscribe({
      next: () => {
        this.loading.set(false);
        this.step.set('done');
      },
      error: (response: HttpErrorResponse) => this.fail(this.codeForm, response),
    });
  }

  resend(): void {
    this.registration.resend(this.email).subscribe({
      next: (response) => {
        this.notifications.success(response.message);
        this.resendCooldown.set(RESEND_COOLDOWN_SECONDS);
      },
      error: (response: HttpErrorResponse) => this.fail(this.codeForm, response),
    });
  }

  backToDetails(): void {
    this.step.set('form');
    this.codeForm.reset();
  }

  private start(): void {
    this.loading.set(true);
    this.formError.set(null);
    clearServerErrors(this.detailsForm);
    clearServerErrors(this.codeForm);
  }

  private fail(
    form: typeof this.detailsForm | typeof this.codeForm,
    response: HttpErrorResponse
  ): void {
    this.loading.set(false);

    // Anything the server complained about that has no field on screen becomes a
    // form-level message instead of being dropped.
    const unmatched = applyServerErrors(form, response);

    this.formError.set(unmatched[0] ?? null);
  }
}
