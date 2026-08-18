import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';

import { LanguageService } from '../services/language.service';
import { TokenService } from '../services/token.service';

/**
 * Attaches the Sanctum token and the active language to every API call, so the
 * backend can answer validation and business messages in the user's language.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const token = inject(TokenService).token;
  const language = inject(LanguageService).current();

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': language,
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  return next(request.clone({ setHeaders: headers }));
};
