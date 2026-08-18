<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        $middleware->api(append: [
            SetLocale::class,
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
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getMessage() ?: __('Request failed.'),
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
