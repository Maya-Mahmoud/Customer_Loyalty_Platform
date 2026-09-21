import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map, tap } from 'rxjs';

import { environment } from '../../../environments/environment';
import {
  NewPlan,
  PlanPricing,
  PlatformSettings,
  SubscriptionPlan,
} from '../models/merchant.model';
import { ApiService } from './api.service';
import { PlatformBrandService } from './platform-brand.service';

/**
 * The platform's own settings — what it bills in, and what each plan costs.
 *
 * Separate from AdminMerchantService because the subject is different: that service
 * is about one shop at a time, this one is about the platform every shop is on.
 */
@Injectable({ providedIn: 'root' })
export class PlatformSettingsService {
  private readonly api = inject(ApiService);
  private readonly http = inject(HttpClient);
  private readonly brand = inject(PlatformBrandService);

  get(): Observable<PlatformSettings> {
    return this.api
      .get<{ data: PlatformSettings }>('admin/settings')
      .pipe(map((response) => response.data), tap((settings) => this.brand.set(settings.logo_url)));
  }

  /**
   * The platform's own mark (the counterpart of the shop logo in FR-MER-06).
   *
   * Sent as FormData through HttpClient directly: ApiService serialises JSON, and a
   * multipart body needs the browser to set its own Content-Type boundary.
   *
   * The response is pushed into PlatformBrandService, so every mark on screen —
   * including the one in the sidebar beside this very form — changes at once.
   */
  uploadLogo(file: File): Observable<PlatformSettings> {
    const body = new FormData();
    body.append('image', file);

    return this.http
      .post<{ data: PlatformSettings }>(`${environment.apiUrl}/admin/settings/logo`, body)
      .pipe(map((response) => response.data), tap((settings) => this.brand.set(settings.logo_url)));
  }

  removeLogo(): Observable<PlatformSettings> {
    return this.api
      .delete<{ data: PlatformSettings }>('admin/settings/logo')
      .pipe(map((response) => response.data), tap((settings) => this.brand.set(settings.logo_url)));
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
