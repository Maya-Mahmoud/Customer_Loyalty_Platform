import { Injectable, inject, signal } from '@angular/core';

import { ApiService } from './api.service';

/** The two things a visitor with no account may know about the platform. */
export interface PlatformBrand {
  name: string;
  logo_url: string | null;
}

/**
 * The platform's own mark, held once for the whole application.
 *
 * It is read on pages nobody is signed in to — the landing page, sign-in,
 * registration, the customer's balance lookup — so it cannot live on the session,
 * and it is the same for every visitor, so fetching it per screen would be the same
 * request repeated. Loaded on first use and kept.
 *
 * The supervisor's settings screen pushes a new value in through set() after an
 * upload, so the sidebar beside the form changes with the form rather than on the
 * next reload.
 */
@Injectable({ providedIn: 'root' })
export class PlatformBrandService {
  private readonly api = inject(ApiService);

  private readonly logo = signal<string | null>(null);

  /** Guards against the several marks on one page each firing the same request. */
  private requested = false;

  readonly logoUrl = this.logo.asReadonly();

  load(): void {
    if (this.requested) {
      return;
    }

    this.requested = true;

    this.api.get<{ data: PlatformBrand }>('platform').subscribe({
      next: (response) => this.logo.set(response.data.logo_url),
      /*
       * Left unset rather than retried. A missing logo is not an error the visitor
       * can act on: the mark falls back to the drawn one and the page is complete
       * without it.
       */
      error: () => undefined,
    });
  }

  set(url: string | null): void {
    this.requested = true;
    this.logo.set(url);
  }
}
