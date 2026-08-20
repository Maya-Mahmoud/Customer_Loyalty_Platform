import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  LoyaltyRule,
  LoyaltyRuleForm,
  LoyaltyRuleState,
} from '../models/loyalty-rule.model';
import { ApiService } from './api.service';

/**
 * The loyalty rule of the signed-in merchant (BRD 8.3).
 *
 * There is no update method on purpose: saving publishes a new version, because
 * BR-015 forbids a rule from taking effect over invoices already recorded.
 */
@Injectable({ providedIn: 'root' })
export class LoyaltyRuleService {
  private readonly api = inject(ApiService);

  state(): Observable<LoyaltyRuleState> {
    return this.api.get<LoyaltyRuleState>('loyalty-rule');
  }

  publish(form: LoyaltyRuleForm): Observable<LoyaltyRule> {
    return this.api
      .post<{ data: LoyaltyRule }>('loyalty-rule', form)
      .pipe(map((response) => response.data));
  }
}
