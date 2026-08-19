import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { MerchantStatus } from '../models/auth.model';
import { Paginated } from '../models/api.model';
import { AdminMerchant, PlatformStats, SubscriptionPlan } from '../models/merchant.model';
import { ApiService } from './api.service';

export interface MerchantQuery {
  status?: MerchantStatus | '';
  search?: string;
  page?: number;
  per_page?: number;
}

/**
 * The supervisor console of BRD 9.1. Every call here is refused by the server for
 * any role but the platform supervisor.
 */
@Injectable({ providedIn: 'root' })
export class AdminMerchantService {
  private readonly api = inject(ApiService);

  list(query: MerchantQuery = {}): Observable<Paginated<AdminMerchant>> {
    return this.api.get<Paginated<AdminMerchant>>('admin/merchants', { ...query });
  }

  get(id: number): Observable<AdminMerchant> {
    return this.api
      .get<{ data: AdminMerchant }>(`admin/merchants/${id}`)
      .pipe(map((response) => response.data));
  }

  stats(): Observable<PlatformStats> {
    return this.api.get<PlatformStats>('admin/stats');
  }

  plans(): Observable<SubscriptionPlan[]> {
    return this.api
      .get<{ data: SubscriptionPlan[] }>('admin/subscription-plans')
      .pipe(map((response) => response.data));
  }

  /** Emails the owner a fresh password-setting link; the link is never returned. */
  resendInvitation(id: number): Observable<AdminMerchant> {
    return this.decide(`admin/merchants/${id}/resend-invitation`);
  }

  activate(id: number): Observable<AdminMerchant> {
    return this.decide(`admin/merchants/${id}/activate`);
  }

  /** BRD FR-ADM-02 makes the reason mandatory on both of these. */
  reject(id: number, reason: string): Observable<AdminMerchant> {
    return this.decide(`admin/merchants/${id}/reject`, { reason });
  }

  suspend(id: number, reason: string): Observable<AdminMerchant> {
    return this.decide(`admin/merchants/${id}/suspend`, { reason });
  }

  assignPlan(
    id: number,
    planId: number,
    endsAt: string
  ): Observable<AdminMerchant> {
    return this.api
      .put<{ data: AdminMerchant }>(`admin/merchants/${id}/subscription`, {
        subscription_plan_id: planId,
        subscription_ends_at: endsAt,
      })
      .pipe(map((response) => response.data));
  }

  private decide(path: string, body?: unknown): Observable<AdminMerchant> {
    return this.api
      .post<{ message: string; data: AdminMerchant }>(path, body)
      .pipe(map((response) => response.data));
  }
}
