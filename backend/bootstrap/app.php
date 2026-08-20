<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(
            append: [SetLocale::class],
            /*
             * Route model binding is deliberately taken out of the group and
             * re-added inside the authenticated routes, after ResolveTenant.
             *
             * In the group it runs before the tenant is pinned, so MerchantScope is
             * not yet active and an id belonging to another merchant — or to the
             * platform supervisor, who belongs to none — resolves happily. That
             * turns every `{user}` and `{branch}` route into a hole in the isolation
             * of BRD FR-ADM-06. Binding after the tenant is set closes it, because
             * the scope is then part of the lookup itself.
             */
            remove: [SubstituteBindings::class],
        );

        $middleware->alias([
            // Pins the request to the signed-in user's merchant so the global
            // scope can enforce BRD FR-ADM-06 without per-query where clauses.
            'tenant' => ResolveTenant::class,
            // Locks out disabled users and suspended merchants (BRD FR-ADM-03).
            'account.active' => EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API failure answers with the same envelope so the Angular
        // error interceptor has a single shape to parse.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            [$status, $message, $errors] = match (true) {
                $e instanceof ValidationException => [
                    422,
                    __('The given data was invalid.'),
                    $e->errors(),
                ],
                $e instanceof AuthenticationException => [
                    401,
                    __('Unauthenticated.'),
                    null,
                ],
                $e instanceof AuthorizationException => [
                    403,
                    $e->getMessage() ?: __('This action is unauthorized.'),
                    null,
                ],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [
                    404,
                    __('Resource not found.'),
                    null,
                ],
                /*
                 * A mail transport failure is an operational condition we expect,
                 * not an unexpected crash. It is answered with a plain message
                 * even in debug, because the driver's own message names the SMTP
                 * host, the account and the provider's reply — and registration is
                 * a public, unauthenticated form. The details still reach the log.
                 */
                $e instanceof TransportExceptionInterface => [
                    503,
                    __('We could not send the email just now. Please try again in a few minutes.'),
                    null,
                ],
                /*
                 * Put the message through the translator. Ours arrive already
                 * translated and pass through untouched, but the framework's own —
                 * "Too Many Attempts." from the rate limiter, most visibly on the
                 * login form — would otherwise reach an Arabic user in English.
                 */
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getMessage() !== '' ? __($e->getMessage()) : __('Request failed.'),
                    null,
                ],
                default => [
                    500,
                    config('app.debug') ? $e->getMessage() : __('Server error.'),
                    null,
                ],
            };

            $payload = [
                'message' => $message,
            ];

            if ($errors !== null) {
                $payload['errors'] = $errors;
            }

            if ($status === 500 && config('app.debug')) {
                $payload['exception'] = $e::class;
                $payload['file'] = $e->getFile().':'.$e->getLine();
            }

            return response()->json($payload, $status);
        });
    })->create();
