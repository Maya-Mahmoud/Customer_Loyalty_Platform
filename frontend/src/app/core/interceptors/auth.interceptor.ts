import { HttpInterceptorFn } from '@angular/common/http';
import { Injector, inject } from '@angular/core';

import { LanguageService } from '../services/language.service';
import { TokenService } from '../services/token.service';
import { isApiRequest } from './is-api-request';

/**
 * Attaches the Sanctum token and the active language to every API call, so the
 * backend can answer validation and business messages in the user's language.
 *
 * Static assets are left untouched: they need no token, and sending one to a file
 * request is pointless at best.
 *
 * LanguageService is resolved on demand rather than injected up front. It depends
 * on TranslateService, which loads its files through HttpClient, which runs this
 * interceptor — an eager injection would close that loop and Angular would report
 * a circular dependency (NG0200). Combined with the asset guard above, the
 * translation fetch never reaches this code at all.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const injector = inject(Injector);

  if (!isApiRequest(request.url)) {
    return next(request);
  }

  const token = injector.get(TokenService).token;
  const language = injector.get(LanguageService).current();

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': language,
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  return next(request.clone({ setHeaders: headers }));
};
