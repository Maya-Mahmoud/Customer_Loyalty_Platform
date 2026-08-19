import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { RegistrationForm, RegistrationStarted } from '../models/merchant.model';
import { ApiService } from './api.service';

/**
 * Public endpoints of BRD 8.1. Nothing here signs anyone in — the account stays
 * inert until a supervisor activates it.
 *
 * Only the email address is verified; the owner's phone is captured but not
 * proven. See MerchantRegistrationService on the server for the reasoning.
 */
@Injectable({ providedIn: 'root' })
export class RegistrationService {
  private readonly api = inject(ApiService);

  submit(form: RegistrationForm): Observable<RegistrationStarted> {
    return this.api.post<RegistrationStarted>('registration', form);
  }

  verify(email: string, code: string): Observable<{ message: string }> {
    return this.api.post<{ message: string }>('registration/verify', { email, code });
  }

  resend(email: string): Observable<{ message: string }> {
    return this.api.post<{ message: string }>('registration/resend', { email });
  }
}
