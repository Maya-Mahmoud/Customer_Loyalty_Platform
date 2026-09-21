/*
 * The development environment: the API on this machine.
 *
 * Pointed at the deployed server for a while, and every call from `npm start` was
 * blocked before it left the browser — the server allows one origin (FRONTEND_URL
 * in its .env), and that is the live site, not localhost. The error on screen said
 * the server could not be reached, which is what a browser reports when it refuses
 * to send a request at all.
 *
 * environment.production.ts is the one that carries the live address, and
 * angular.json swaps this file for it on `ng build`.
 */
export const environment = {
  production: false,
  apiUrl: 'http://localhost:8000/api/v1',
  defaultLanguage: 'ar',
  supportedLanguages: ['ar', 'en']
};