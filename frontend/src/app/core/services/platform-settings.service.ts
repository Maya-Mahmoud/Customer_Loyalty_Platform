import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  NewPlan,
  PlanPricing,
  PlatformSettings,
  SubscriptionPlan,
} from '../models/merchant.model';
import { ApiService } from './api.service';

/**
 * The platform's own settings — what it bills in, and what each plan costs.
 *
 * Separate from AdminMerchantService because the subject is different: that service
 * is about one shop at a time, this one is about the platform every shop is on.
 */
@Injectable({ providedIn: 'root' })
export class PlatformSettingsService {
  private readonly api = inject(ApiService);

  get(): Observable<PlatformSettings> {
    return this.api
      .get<{ data: PlatformSettings }>('admin/settings')
      .pipe(map((response) => response.data));
  }

  /** Returns the whole settings payload, so the screen never reasons about
      which parts of it a save may have moved. */
  setBillingCurrency(currency: string): Observable<PlatformSettings> {
    return this.api
      .put<{ data: PlatformSettings }>('admin/settings', { billing_currency: currency })
      .pipe(map((response) => response.data));
  }

  savePlan(
    id: number,
    pricing: PlanPricing & { is_active?: boolean }
  ): Observable<SubscriptionPlan> {
    return this.api
      .put<{ data: SubscriptionPlan }>(`admin/subscription-plans/${id}`, pricing)
      .pipe(map((response) => response.data));
  }

  /** A new plan on the price list. It goes on sale immediately; withdrawing one
      later is a save with is_active false, never a delete. */
  addPlan(plan: NewPlan): Observable<SubscriptionPlan> {
    return this.api
      .post<{ data: SubscriptionPlan }>('admin/subscription-plans', plan)
      .pipe(map((response) => response.data));
  }
}
