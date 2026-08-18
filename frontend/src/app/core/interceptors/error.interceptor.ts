import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { ApiError } from '../models/api.model';
import { NotificationService } from '../services/notification.service';
import { TokenService } from '../services/token.service';

/**
 * Turns the API's error envelope into a user-facing message. Validation errors
 * (422) are left to the form that issued the request, since they belong next to
 * the offending fields rather than in a toast.
 */
export const errorInterceptor: HttpInterceptorFn = (request, next) => {
  const router = inject(Router);
  const tokens = inject(TokenService);
  const notifications = inject(NotificationService);

  return next(request).pipe(
    catchError((response: HttpErrorResponse) => {
      if (response.status === 0) {
        notifications.error('errors.network');
        return throwError(() => response);
      }

      const body = response.error as ApiError | null;

      if (response.status === 401) {
        tokens.clear();
        router.navigate(['/login']);
        return throwError(() => response);
      }

      if (response.status !== 422) {
        notifications.error(body?.message ?? 'errors.unexpected');
      }

      return throwError(() => response);
    })
  );
};
