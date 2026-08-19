import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { map } from 'rxjs';

import { AuthService } from '../services/auth.service';

/**
 * Lets a protected route render only once a profile is in memory. On a reload
 * that means waiting for /auth/me, so the shell never paints with an empty user.
 */
export const authGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return auth.restore().pipe(
    map((user) => {
      if (user !== null) {
        return true;
      }

      // Remembered so the user returns to where they were heading.
      return router.createUrlTree(['/login'], {
        queryParams: state.url === '/' ? undefined : { returnUrl: state.url },
      });
    })
  );
};
