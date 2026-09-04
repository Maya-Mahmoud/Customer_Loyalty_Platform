import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiService } from './api.service';

export interface BalanceStore {
  id: number;
  name: string;
  city: string;
}

export interface BalanceClaim {
  merchant_id: number;
  phone: string;
  invoice_number?: string | null;
  invoice_date?: string | null;
  amount?: number | null;
}

export interface BalanceCard {
  store: string;
  city: string;
  currency: string;
  logo_url: string | null;

  name: string | null;
  balance: number;
  invoice_count: number;
  is_eligible: boolean;
  amount_remaining: number | null;
  invoices_remaining: number | null;
  progress: number;

  /** What the reward will be, so the balance means something. */
  reward: { type: string; value: string; max_discount: string | null } | null;

  vouchers: { code: string; amount: string; expires_at: string | null }[];
  last_purchase_at: string | null;
}

/**
 * The customer's own balance (BRD FR-CUS-12).
 *
 * No token: these are the only two endpoints in the app a signed-out visitor may
 * call, because the person asking has no account to sign in to (BR-001).
 */
@Injectable({ providedIn: 'root' })
export class BalanceService {
  private readonly api = inject(ApiService);

  stores(): Observable<BalanceStore[]> {
    return this.api
      .get<{ data: BalanceStore[] }>('balance/stores')
      .pipe(map((response) => response.data));
  }

  lookup(claim: BalanceClaim): Observable<BalanceCard> {
    return this.api
      .post<{ data: BalanceCard }>('balance', claim)
      .pipe(map((response) => response.data));
  }
}
