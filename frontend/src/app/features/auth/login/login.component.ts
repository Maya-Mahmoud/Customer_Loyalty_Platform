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

import { ApiError } from '../../../core/models/api.model';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';

@Component({
  selector: 'app-login',
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
  templateUrl: './login.component.html',
})
export class LoginComponent {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly language = inject(LanguageService);

  readonly currentLanguage = this.language.current;
  readonly loading = signal(false);
  readonly hidePassword = signal(true);

  /**
   * Sign-in failures arrive as 422 so the error interceptor stays silent; the
   * message belongs here, above the form, rather than in a toast that vanishes.
   */
  readonly serverError = signal<string | null>(null);

  readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });

  toggleLanguage(): void {
    this.language.toggle();
  }

  submit(): void {
    if (this.form.invalid || this.loading()) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading.set(true);
    this.serverError.set(null);

    this.auth.login(this.form.getRawValue()).subscribe({
      next: () => {
        const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl');
        void this.router.navigateByUrl(returnUrl ?? this.auth.homeRoute());
      },
      error: (response: HttpErrorResponse) => {
        this.loading.set(false);
        this.serverError.set(this.messageFrom(response));
      },
    });
  }

  private messageFrom(response: HttpErrorResponse): string {
    const body = response.error as ApiError | null;

    // 422 carries the specific reason under the field; anything else falls back
    // to the envelope message the API always sends.
    const fieldError = body?.errors?.['email']?.[0];

    if (fieldError !== undefined) {
      return fieldError;
    }

    if (response.status === 429) {
      return 'auth.tooManyAttempts';
    }

    return body?.message ?? 'errors.unexpected';
  }
}
