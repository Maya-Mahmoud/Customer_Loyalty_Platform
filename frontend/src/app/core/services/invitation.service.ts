import { Injectable, inject } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { InvitationDetails } from '../models/merchant.model';
import { LoginResponse } from '../models/auth.model';
import { ApiService } from './api.service';
import { TokenService } from './token.service';

/**
 * Setting a password from an invitation link (BRD FR-BRN-04). The token in the
 * URL is the authorisation, so none of this needs an existing session.
 */
@Injectable({ providedIn: 'root' })
export class InvitationService {
  private readonly api = inject(ApiService);
  private readonly tokens = inject(TokenService);

  /** Checked before the form renders so an expired link says so up front. */
  load(token: string): Observable<InvitationDetails> {
    return this.api.get<InvitationDetails>(`invitations/${token}`);
  }

  accept(token: string, password: string, confirmation: string): Observable<LoginResponse> {
    return this.api
      .post<LoginResponse>(`invitations/${token}`, {
        password,
        password_confirmation: confirmation,
      })
      .pipe(tap((response) => this.tokens.set(response.token)));
  }
}
