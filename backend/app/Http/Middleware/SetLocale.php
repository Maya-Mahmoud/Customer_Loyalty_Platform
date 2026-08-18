<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale from the Accept-Language header so the API can
 * return validation and business messages in the caller's language (NFR-07).
 */
class SetLocale
{
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = substr((string) $request->header('Accept-Language'), 0, 2);

        if (in_array($requested, self::SUPPORTED, true)) {
            app()->setLocale($requested);
        }

        return $next($request);
    }
}
