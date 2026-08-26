import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { environment } from '../../../environments/environment';

import {
  FraudSignal,
  Report,
  ReportBranchRow,
  ReportCustomers,
  ReportQuery,
  ReportRewards,
  ReportStaffRow,
  ReportSummary,
} from '../models/report.model';
import { ApiService } from './api.service';

/**
 * The reports of BRD 9.
 *
 * The branch is sent when the user chose one, but the server has the last word: a
 * branch manager is answered with their own branch whatever they ask for (BRD
 * FR-BRN-03), which is why every response carries the period it actually used.
 */
@Injectable({ providedIn: 'root' })
export class ReportService {
  private readonly api = inject(ApiService);
  private readonly http = inject(HttpClient);

  summary(query: ReportQuery): Observable<Report<ReportSummary>> {
    return this.api.get<Report<ReportSummary>>('reports/summary', this.params(query));
  }

  customers(query: ReportQuery): Observable<Report<ReportCustomers>> {
    return this.api.get<Report<ReportCustomers>>('reports/customers', this.params(query));
  }

  branches(query: ReportQuery): Observable<Report<ReportBranchRow[]>> {
    return this.api.get<Report<ReportBranchRow[]>>('reports/branches', this.params(query));
  }

  rewards(query: ReportQuery): Observable<Report<ReportRewards>> {
    return this.api.get<Report<ReportRewards>>('reports/rewards', this.params(query));
  }

  staff(query: ReportQuery): Observable<Report<ReportStaffRow[]>> {
    return this.api.get<Report<ReportStaffRow[]>>('reports/staff', this.params(query));
  }

  /** The anti-fraud signals of BRD 12 — the owner alone reaches this. */
  signals(query: ReportQuery): Observable<Report<FraudSignal[]>> {
    return this.api.get<Report<FraudSignal[]>>('reports/signals', this.params(query));
  }

  /** Empty values are dropped rather than sent as blanks the server has to ignore. */
  private params(query: ReportQuery): Record<string, string> {
    const params: Record<string, string> = {};

    if (query.from) {
      params['from'] = query.from;
    }

    if (query.to) {
      params['to'] = query.to;
    }

    if (query.branch_id) {
      params['branch_id'] = String(query.branch_id);
    }

    return params;
  }

  /**
   * Exporting the customer base (BRD BR-019, customers.export).
   *
   * Fetched as a blob rather than opened as a link: the token lives in memory and
   * travels on the Authorization header, so a plain href would arrive unauthenticated.
   * The file is handed to the browser from the blob once it is here.
   */
  exportCustomers(query: ReportQuery & { only_consented?: boolean }): Observable<Blob> {
    const params: Record<string, string> = {};

    if (query.from) {
      params['from'] = query.from;
    }

    if (query.to) {
      params['to'] = query.to;
    }

    if (query.branch_id) {
      params['branch_id'] = String(query.branch_id);
    }

    if (query.only_consented) {
      params['only_consented'] = '1';
    }

    return this.http
      .get(`${environment.apiUrl}/customers/export`, { params, responseType: 'blob' })
      .pipe(map((blob) => blob as Blob));
  }
}
