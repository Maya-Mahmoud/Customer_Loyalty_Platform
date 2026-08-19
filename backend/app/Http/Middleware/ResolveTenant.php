<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the request to the authenticated user's merchant.
 *
 * Runs after authentication and before any controller, so every query issued
 * downstream is already filtered by MerchantScope. The platform supervisor has no
 * merchant and stays unscoped on purpose (BRD 7.1).
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->tenant->set($user->merchant_id);
        }

        return $next($request);
    }
}
