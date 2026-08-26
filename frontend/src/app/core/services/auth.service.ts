import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, catchError, map, of, tap } from 'rxjs';

import {
  AuthMerchant,
  AuthUser,
  LoginCredentials,
  LoginResponse,
  MeResponse,
  Permission,
} from '../models/auth.model';
import { ApiService } from './api.service';
import { TokenService } from './token.service';

/**
 * Holds the signed-in user for the whole app.
 *
 * The token lives in TokenService and the profile lives here; a page reload has
 * only the token, so the guard asks restore() to fetch the profile again before
 * letting a protected route render.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiService);
  private readonly tokens = inject(TokenService);
  private readonly router = inject(Router);

  private readonly currentUser = signal<AuthUser | null>(null);

  readonly user = this.currentUser.asReadonly();
  readonly isAuthenticated = computed(() => this.currentUser() !== null);
  readonly role = computed(() => this.currentUser()?.role ?? null);
  readonly merchant = computed(() => this.currentUser()?.merchant ?? null);
  readonly branch = computed(() => this.currentUser()?.branch ?? null);

  login(credentials: LoginCredentials): Observable<AuthUser> {
    return this.api.post<LoginResponse>('auth/login', credentials).pipe(
      tap((response) => {
        this.tokens.set(response.token);
        this.currentUser.set(response.user);
      }),
      map((response) => response.user)
    );
  }

  /**
   * Rebuilds the session from a stored token. Resolves to null when there is no
   * token, or when the token is no longer accepted.
   */
  restore(): Observable<AuthUser | null> {
    if (this.currentUser() !== null) {
      return of(this.currentUser());
    }

    if (!this.tokens.hasToken) {
      return of(null);
    }

    return this.api.get<MeResponse>('auth/me').pipe(
      tap((response) => this.currentUser.set(response.user)),
      map((response) => response.user),
      catchError(() => {
        this.tokens.clear();
        this.currentUser.set(null);
        return of(null);
      })
    );
  }

  logout(): void {
    // Clear locally first so the UI reacts immediately; revoking the token
    // server-side is best-effort and must not block the redirect.
    const finish = () => {
      this.tokens.clear();
      this.currentUser.set(null);
      void this.router.navigate(['/login']);
    };

    this.api.post('auth/logout').subscribe({ next: finish, error: finish });
  }

  /**
   * Replaces the held profile after the user edits their own account, so the name
   * and picture in the toolbar change with the form rather than on the next reload.
   */
  setUser(user: AuthUser): void {
    this.currentUser.set(user);
  }

  /**
   * Same, for the store the user belongs to: its logo and currency appear on
   * screens far away from the settings form.
   */
  setMerchant(merchant: AuthMerchant): void {
    this.currentUser.update((user) => (user === null ? user : { ...user, merchant }));
  }

  /**
   * Presentation check only — used to hide what a role cannot do. Every action is
   * still authorised on the server (BRD FR-SEC-01).
   */
  has(permission: Permission): boolean {
    return this.currentUser()?.permissions.includes(permission) ?? false;
  }

  hasAny(permissions: Permission[]): boolean {
    return permissions.some((permission) => this.has(permission));
  }

  /**
   * Where a user lands after signing in.
   *
   * A sales rep goes straight to the till — it is the only screen they need, and
   * a dashboard they cannot act on would be a wasted tap at the start of every
   * shift.
   */
  homeRoute(): string {
    const role = this.currentUser()?.role;

    if (role === 'platform_admin') {
      return '/admin/merchants';
    }

    return role === 'sales_rep' ? '/pos' : '/dashboard';
  }
}
