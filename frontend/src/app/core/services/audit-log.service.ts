import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { ApiService } from './api.service';

export interface AuditLogEntry {
  id: number;
  action: string;
  /** A short name such as "Redemption", not the class path. */
  entity_type: string | null;
  entity_id: number | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string | null;
  user: { id: number; name: string; role: string } | null;
  /** Only meaningful to the platform supervisor, who sees more than one store. */
  merchant?: string | null;
}

export interface AuditLogPage {
  data: AuditLogEntry[];
  meta: { current_page: number; last_page: number; total: number; per_page: number };
}

/** The shape of the trail, for the panel above the list. */
export interface AuditLogStats {
  total: number;
  by_action: { action: string; total: number }[];
  /** Everything outside the top five, folded into one bucket. */
  other_total: number;
  by_hour: { hour: number; total: number }[];
}

export interface AuditLogQuery {
  action?: string | null;
  user_id?: number | null;
  from?: string | null;
  to?: string | null;
  page?: number;
}

/**
 * Reading the audit trail (BRD FR-SEC-02).
 *
 * Read-only by design: there is no method here that writes, because there is no
 * endpoint to write to.
 */
@Injectable({ providedIn: 'root' })
export class AuditLogService {
  private readonly api = inject(ApiService);

  page(query: AuditLogQuery): Observable<AuditLogPage> {
    return this.api.get<AuditLogPage>('audit-logs', {
      action: query.action ?? undefined,
      user_id: query.user_id ?? undefined,
      from: query.from ?? undefined,
      to: query.to ?? undefined,
      page: query.page ?? 1,
    });
  }

  /**
   * The two aggregates behind the charts. Sent the same filters as the list, because
   * a chart that ignores the filter beside it is a chart that lies.
   */
  stats(query: AuditLogQuery): Observable<AuditLogStats> {
    return this.api.get<AuditLogStats>('audit-logs/stats', {
      action: query.action ?? undefined,
      from: query.from ?? undefined,
      to: query.to ?? undefined,
    });
  }

  /** The action names actually present, so the filter reflects what happened. */
  actions(): Observable<string[]> {
    return this.api
      .get<{ data: string[] }>('audit-logs/actions')
      .pipe(map((response) => response.data));
  }
}
