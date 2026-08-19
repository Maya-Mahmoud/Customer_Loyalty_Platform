<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BRD FR-ADM-03: a suspended merchant's users are locked out with an explanatory
 * message.
 *
 * Checked on every request, not only at sign-in, so a suspension takes effect
 * immediately for anyone already holding a token.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->status->allowsAccess()) {
            return response()->json([
                'message' => __('Your account has been disabled. Please contact your store owner.'),
            ], 403);
        }

        $merchant = $user->merchant;

        if ($user->role->requiresMerchant() && ($merchant === null || ! $merchant->isActive())) {
            return response()->json([
                'message' => __('This store account is not active. Please contact platform support.'),
                'merchant_status' => $merchant?->status,
            ], 403);
        }

        return $next($request);
    }
}
