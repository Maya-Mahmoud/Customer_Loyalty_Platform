import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  Adjustment,
  AdjustmentForm,
  AdjustmentResult,
  CorrectionForm,
  CorrectionResult,
  CustomerCard,
  InvoiceCorrection,
  InvoiceForm,
  InvoiceResult,
  LookupResult,
  MyRecentInvoice,
  RedeemForm,
  RedeemResult,
  Redemption,
  RedemptionPreview,
  RegisterCustomerForm,
} from '../models/sales.model';
import { ApiService } from './api.service';

/**
 * The point of sale (BRD 8.4, 8.5).
 *
 * Each call returns the whole customer card rather than an id to follow up on.
 * BRD NFR-05 allows thirty seconds for a complete entry, and a second round trip
 * spends a real part of that while a customer waits.
 */
@Injectable({ providedIn: 'root' })
export class SalesService {
  private readonly api = inject(ApiService);

  /** Answers found:false for an unknown number — a normal outcome, not an error. */
  lookup(phone: string): Observable<LookupResult> {
    return this.api.get<LookupResult>('customers/lookup', { phone });
  }

  register(form: RegisterCustomerForm): Observable<CustomerCard> {
    return this.api
      .post<{ customer: CustomerCard }>('customers', form)
      .pipe(map((response) => response.customer));
  }

  /** The full card with purchase history. */
  card(id: number): Observable<CustomerCard> {
    return this.api
      .get<{ customer: CustomerCard }>(`customers/${id}`)
      .pipe(map((response) => response.customer));
  }

  setConsent(id: number, granted: boolean): Observable<CustomerCard> {
    return this.api
      .put<{ customer: CustomerCard }>(`customers/${id}/consent`, { consent_given: granted })
      .pipe(map((response) => response.customer));
  }

  /** Returns the customer's position afterwards, so eligibility shows at once. */
  recordSale(form: InvoiceForm): Observable<InvoiceResult> {
    return this.api.post<InvoiceResult>('invoices', form);
  }

  // ---------------------------------------------------------------
  // Paying a reward out (BRD 8.6)
  // ---------------------------------------------------------------

  /**
   * The figure the manager is about to authorise. Fetched separately from the
   * payment so the confirmation of BRD 8.6 step 4 shows a real number, and so a
   * mis-click on a button cannot move money.
   */
  previewRedemption(customerId: number): Observable<RedemptionPreview> {
    return this.api.get<RedemptionPreview>(`customers/${customerId}/redemptions/preview`);
  }

  redeem(customerId: number, form: RedeemForm = {}): Observable<RedeemResult> {
    return this.api.post<RedeemResult>(`customers/${customerId}/redemptions`, form);
  }

  /** Past rewards for the customer card (BRD FR-RED-07). */
  redemptions(customerId: number): Observable<Redemption[]> {
    return this.api
      .get<{ data: Redemption[] }>(`customers/${customerId}/redemptions`)
      .pipe(map((response) => response.data));
  }

  // ---------------------------------------------------------------
  // Cancelling and returning invoices (BRD 8.7)
  // ---------------------------------------------------------------

  /**
   * Raises the request. Whether it takes effect now or waits for a manager is the
   * server's call (BR-012), and the answer comes back in `applied`.
   */
  requestCorrection(invoiceId: number, form: CorrectionForm): Observable<CorrectionResult> {
    return this.api.post<CorrectionResult>(`invoices/${invoiceId}/corrections`, form);
  }

  /** The queue a manager decides on (BRD FR-INV-08). */
  pendingCorrections(): Observable<InvoiceCorrection[]> {
    return this.api
      .get<{ data: InvoiceCorrection[] }>('corrections')
      .pipe(map((response) => response.data));
  }

  decideCorrection(
    id: number,
    approve: boolean,
    note: string | null = null
  ): Observable<InvoiceCorrection> {
    return this.api
      .post<{ data: InvoiceCorrection }>(`corrections/${id}/${approve ? 'approve' : 'reject'}`, {
        review_note: note,
      })
      .pipe(map((response) => response.data));
  }

  // ---------------------------------------------------------------
  // Correcting a balance by hand (BRD 7.2, ledger.adjust)
  // ---------------------------------------------------------------

  adjustBalance(customerId: number, form: AdjustmentForm): Observable<AdjustmentResult> {
    return this.api.post<AdjustmentResult>(`customers/${customerId}/adjustments`, form);
  }

  adjustments(customerId: number): Observable<Adjustment[]> {
    return this.api
      .get<{ data: Adjustment[] }>(`customers/${customerId}/adjustments`)
      .pipe(map((response) => response.data));
  }

  // ---------------------------------------------------------------
  // Erasing a customer at their request (BRD FR-CUS-10, section 16)
  // ---------------------------------------------------------------

  /**
   * A POST, not a DELETE: the sales and the ledger stay exactly as they were, and
   * it is the person who is removed from them.
   */
  anonymizeCustomer(
    customerId: number,
    reason: string
  ): Observable<{ message: string; balance_written_off: number; vouchers_cancelled: number }> {
    return this.api.post<{
      message: string;
      balance_written_off: number;
      vouchers_cancelled: number;
    }>(`customers/${customerId}/anonymize`, { reason });
  }

  /** The caller's own entries from today (BR-019: their work, not a customer list). */
  myRecentInvoices(): Observable<MyRecentInvoice[]> {
    return this.api
      .get<{ data: MyRecentInvoice[] }>('invoices/mine')
      .pipe(map((response) => response.data));
  }
}
