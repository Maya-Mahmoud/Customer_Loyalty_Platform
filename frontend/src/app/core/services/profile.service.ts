import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map, tap } from 'rxjs';

import { environment } from '../../../environments/environment';
import { AuthMerchant, AuthUser } from '../models/auth.model';
import { ApiService } from './api.service';
import { AuthService } from './auth.service';

export interface ProfileForm {
  name: string;
  phone: string;
}

export interface PasswordChangeForm {
  current_password: string;
  password: string;
  password_confirmation: string;
}

export interface StoreProfileForm {
  trade_name: string | null;
  city: string;
  phone: string;
  currency: string;
}

/**
 * The signed-in user's own account, and the store's own profile.
 *
 * Every call that changes the account pushes the updated user back into
 * AuthService, so the name and picture in the toolbar change with the form rather
 * than on the next reload.
 *
 * Uploads go through HttpClient directly with FormData: ApiService serialises JSON,
 * and setting a Content-Type by hand on a multipart body drops the boundary the
 * server needs to parse it.
 */
@Injectable({ providedIn: 'root' })
export class ProfileService {
  private readonly api = inject(ApiService);
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);

  updateProfile(form: ProfileForm): Observable<AuthUser> {
    return this.api
      .put<{ user: AuthUser }>('auth/profile', form)
      .pipe(map((response) => response.user), tap((user) => this.auth.setUser(user)));
  }

  changePassword(form: PasswordChangeForm): Observable<{ message: string }> {
    return this.api.put<{ message: string }>('auth/profile/password', form);
  }

  uploadAvatar(file: File): Observable<AuthUser> {
    const body = new FormData();
    body.append('image', file);

    return this.http
      .post<{ user: AuthUser }>(`${environment.apiUrl}/auth/profile/avatar`, body)
      .pipe(map((response) => response.user), tap((user) => this.auth.setUser(user)));
  }

  removeAvatar(): Observable<AuthUser> {
    return this.api
      .delete<{ user: AuthUser }>('auth/profile/avatar')
      .pipe(map((response) => response.user), tap((user) => this.auth.setUser(user)));
  }

  store(): Observable<AuthMerchant> {
    return this.api.get<{ data: AuthMerchant }>('store').pipe(map((response) => response.data));
  }

  updateStore(form: StoreProfileForm): Observable<AuthMerchant> {
    return this.api
      .put<{ data: AuthMerchant }>('store', form)
      .pipe(map((response) => response.data), tap((merchant) => this.auth.setMerchant(merchant)));
  }

  uploadLogo(file: File): Observable<AuthMerchant> {
    const body = new FormData();
    body.append('image', file);

    return this.http
      .post<{ data: AuthMerchant }>(`${environment.apiUrl}/store/logo`, body)
      .pipe(map((response) => response.data), tap((merchant) => this.auth.setMerchant(merchant)));
  }

  removeLogo(): Observable<AuthMerchant> {
    return this.api
      .delete<{ data: AuthMerchant }>('store/logo')
      .pipe(map((response) => response.data), tap((merchant) => this.auth.setMerchant(merchant)));
  }
}
