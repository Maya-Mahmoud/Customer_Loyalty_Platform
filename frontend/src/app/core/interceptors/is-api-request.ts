import { environment } from '../../../environments/environment';

/**
 * Whether a request is aimed at our own API.
 *
 * HttpClient is also used for static assets — the translation files are fetched
 * through it — and those must not pick up an Authorization header or be reported
 * as API failures. A missing translation file reported as "cannot reach the
 * server" is doubly confusing, because the toast saying so cannot be translated
 * either.
 */
export function isApiRequest(url: string): boolean {
  return url.startsWith(environment.apiUrl);
}
