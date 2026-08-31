import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';

import { applyServerErrors, clearServerErrors } from '../../../core/forms/server-errors';
import { InvitationDetails } from '../../../core/models/merchant.model';
import { AuthService } from '../../../core/services/auth.service';
import { InvitationService } from '../../../core/services/invitation.service';
import { LanguageService } from '../../../core/services/language.service';

/**
 * Where an invitation link lands (BRD FR-BRN-04).
 *
 * The link is validated before the form appears, so an expired one says so
 * instead of failing after the user has typed a password twice.
 */
@Component({
  selector: 'app-set-password',
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
  templateUrl: './set-password.component.html',
})
export class SetPasswordComponent {
  private readonly fb = inject(FormBuilder);
  private readonly invitations = inject(InvitationService);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly language = inject(LanguageService);

  private readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  readonly currentLanguage = this.language.current;
  readonly checking = signal(true);
  readonly saving = signal(false);
  readonly invalidLink = signal(false);
  readonly invitation = signal<InvitationDetails | null>(null);
  readonly formError = signal<string | null>(null);
  readonly hidePassword = signal(true);

  readonly form = this.fb.nonNullable.group({
    // Mirrors the server policy behind BRD FR-SEC-03 so the rules are visible
    // before submitting rather than only in the response.
    password: [
      '',
      [
        Validators.required,
        Validators.minLength(6),
      ],
    ],
    password_confirmation: ['', [Validators.required]],
  });

  constructor() {
    this.invitations.load(this.token).subscribe({
      next: (details) => {
        this.invitation.set(details);
        this.checking.set(false);
      },
      error: () => {
        this.invalidLink.set(true);
        this.checking.set(false);
      },
    });
  }

  toggleLanguage(): void {
    this.language.toggle();
  }

  get mismatched(): boolean {
    const { password, password_confirmation } = this.form.getRawValue();

    return password_confirmation.length > 0 && password !== password_confirmation;
  }

  submit(): void {
    if (this.form.invalid || this.mismatched || this.saving()) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    this.formError.set(null);
    clearServerErrors(this.form);

    const { password, password_confirmation } = this.form.getRawValue();

    this.invitations.accept(this.token, password, password_confirmation).subscribe({
      next: () => {
        // The response already contains a working token, so the user goes
        // straight in rather than being bounced to the login form.
        this.auth.restore().subscribe(() => {
          void this.router.navigateByUrl(this.auth.homeRoute());
        });
      },
      error: (response: HttpErrorResponse) => {
        this.saving.set(false);

        const unmatched = applyServerErrors(this.form, response);

        // A dead or already-used link reports on 'token', which has no field.
        this.formError.set(unmatched[0] ?? null);
      },
    });
  }
}
