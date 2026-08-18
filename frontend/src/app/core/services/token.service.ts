import { Injectable } from '@angular/core';

const TOKEN_KEY = 'clp.token';

/**
 * Single owner of the Sanctum token so the interceptor and the future auth
 * service never read localStorage directly.
 */
@Injectable({ providedIn: 'root' })
export class TokenService {
  get token(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  }

  set(token: string): void {
    localStorage.setItem(TOKEN_KEY, token);
  }

  clear(): void {
    localStorage.removeItem(TOKEN_KEY);
  }

  get hasToken(): boolean {
    return this.token !== null;
  }
}
