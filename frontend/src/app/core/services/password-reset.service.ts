import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { LoginResponse } from '../models/auth.model';

/** The reset returns a session plus a confirmation to show the user. */
export interface PasswordResetResponse extends LoginResponse {
  message: string;
}
import { ApiService } from './api.service';
import { TokenService } from './token.service';

/**
 * Recovering a forgotten password with an emailed code.
 *
 * Two calls serving one screen: ask for a code, then send it back with the new
 * password. Nothing here involves following a link.
 */
@Injectable({ providedIn: 'root' })
export class PasswordResetService {
  private readonly api = inject(ApiService);
  private readonly tokens = inject(TokenService);

  request(email: string): Observable<{ message: string; expires_in_minutes: number }> {
    return this.api.post<{ message: string; expires_in_minutes: number }>(
      'auth/password/forgot',
      { email }
    );
  }

  reset(
    email: string,
    code: string,
    password: string,
    confirmation: string
  ): Observable<PasswordResetResponse> {
    return this.api
      .post<PasswordResetResponse>('auth/password/reset', {
        email,
        code,
        password,
        password_confirmation: confirmation,
      })
      .pipe(tap((response) => this.tokens.set(response.token)));
  }
}
