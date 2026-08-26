import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { Injector, inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { ApiError } from '../models/api.model';
import { NotificationService } from '../services/notification.service';
import { TokenService } from '../services/token.service';
import { isApiRequest } from './is-api-request';

/**
 * The endpoint the route guards call to rebuild a session from a stored token.
 * A 401 here is an expected outcome, not an error to report.
 */
function isSessionProbe(url: string): boolean {
  return url.endsWith('/auth/me');
}

/**
 * Turns the API's error envelope into a user-facing message. Validation errors
 * (422) are left to the form that issued the request, since they belong next to
 * the offending fields rather than in a toast.
 *
 * Only API calls are handled. Asset fetches pass straight through — the
 * translation files go through HttpClient too, and reporting one as an
 * unreachable server would be both wrong and untranslatable.
 *
 * Nothing but the Injector is resolved eagerly. NotificationService needs
 * TranslateService, TranslateService loads its files through HttpClient, and
 * HttpClient runs this interceptor — so injecting it up front makes the
 * interceptor part of TranslateService's own construction, which Angular rejects
 * as a circular dependency (NG0200). Resolving on demand breaks that loop.
 */
export const errorInterceptor: HttpInterceptorFn = (request, next) => {
  const injector = inject(Injector);

  if (!isApiRequest(request.url)) {
    return next(request);
  }

  const notify = (message: string): void => injector.get(NotificationService).error(message);

  return next(request).pipe(
    catchError((response: HttpErrorResponse) => {
      if (response.status === 0) {
        notify('errors.network');

        return throwError(() => response);
      }

      const body = response.error as ApiError | null;

      if (response.status === 401) {
        injector.get(TokenService).clear();

        // The session probe is the guards' own call. They already redirect, with
        // a returnUrl, so redirecting here as well would start a second
        // navigation that cancels theirs and leaves a blank page.
        if (!isSessionProbe(request.url)) {
          void injector.get(Router).navigate(['/login']);
        }

        return throwError(() => response);
      }

      /*
       * A failed download answers with JSON inside a Blob, because the request
       * asked for a blob. Reading it back is what turns "something went wrong" into
       * the sentence the server actually sent. Validation errors are reported here
       * too: a download has no form to attach them to.
       */
      if (response.error instanceof Blob) {
        void response.error.text().then((text) => {
          try {
            notify((JSON.parse(text) as ApiError).message ?? 'errors.unexpected');
          } catch {
            notify('errors.unexpected');
          }
        });

        return throwError(() => response);
      }

      if (response.status !== 422) {
        notify(body?.message ?? 'errors.unexpected');
      }

      return throwError(() => response);
    })
  );
};
