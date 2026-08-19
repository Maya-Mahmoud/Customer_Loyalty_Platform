import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { Permission } from '../models/auth.model';
import { AuthService } from '../services/auth.service';
import { NotificationService } from '../services/notification.service';

/**
 * Route-level counterpart of the `can:` middleware on the API. It only prevents
 * a pointless navigation — the server is what actually enforces the permission.
 *
 * Use after authGuard, which guarantees a loaded profile.
 */
export function requirePermission(...permissions: Permission[]): CanActivateFn {
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);
    const notifications = inject(NotificationService);

    if (auth.hasAny(permissions)) {
      return true;
    }

    notifications.error('errors.forbidden');

    return router.parseUrl(auth.homeRoute());
  };
}
