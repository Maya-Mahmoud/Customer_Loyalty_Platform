<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

/**
 * The platform's public identity: its name and its logo.
 *
 * Public because the screens that need it are public — the landing page, sign-in,
 * registration, password recovery, and the customer's own balance lookup all carry
 * the platform's mark, and every one of them is read by somebody with no account.
 *
 * Deliberately nothing else. The supervisor's settings screen returns the billing
 * currency and the whole price list beside the logo; this returns the two things a
 * visitor may see and not one field more.
 */
class PlatformBrandController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => config('app.name'),
                'logo_url' => PlatformSetting::logoUrl(),
            ],
        ]);
    }
}
