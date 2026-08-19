<?php

use App\Http\Controllers\Api\V1\Admin\MerchantController;
use App\Http\Controllers\Api\V1\Admin\PlatformStatsController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MerchantRegistrationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Versioned from the start so a later breaking change can ship as v2 while
| existing clients keep working against v1.
*/

Route::prefix('v1')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'status' => 'ok',
        'locale' => app()->getLocale(),
        'time' => now()->toIso8601String(),
    ]));

    /*
    |----------------------------------------------------------------------
    | Public — no token, so everything here is rate limited
    |----------------------------------------------------------------------
    */

    // Throttled because this is the one endpoint an attacker can call freely.
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1');

    // Merchant self-registration (BRD 8.1). The per-destination resend and
    // attempt caps live in VerificationCodeService; this only limits volume.
    Route::prefix('registration')->middleware('throttle:10,1')->group(function () {
        Route::post('/', [MerchantRegistrationController::class, 'store']);
        Route::post('/verify', [MerchantRegistrationController::class, 'verify']);
        Route::post('/resend', [MerchantRegistrationController::class, 'resend']);
    });

    // Recovery for a forgotten password: ask for a code, then send it back with
    // the new password. Both calls serve one screen, so the user never follows a
    // link or leaves the page.
    Route::prefix('auth/password')->middleware('throttle:10,1')->group(function () {
        Route::post('/forgot', [PasswordResetController::class, 'store']);
        Route::post('/reset', [PasswordResetController::class, 'update']);
    });

    // Setting a password from an invitation link (BRD FR-BRN-04). The token in
    // the URL is the authorisation, so no session is needed.
    Route::prefix('invitations')->middleware('throttle:20,1')->group(function () {
        Route::get('/{token}', [InvitationController::class, 'show']);
        Route::post('/{token}', [InvitationController::class, 'store']);
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated
    |----------------------------------------------------------------------
    | Order matters: verify the token, check the account is not suspended, then
    | pin the merchant so the tenant scope applies to every query downstream.
    */

    Route::middleware(['auth:sanctum', 'account.active', 'tenant'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        /*
         * Platform supervisor only. By the matrix of BRD 7.2 no other role holds
         * merchants.manage_status, so the one gate guards the whole console.
         */
        Route::prefix('admin')
            ->middleware('can:merchants.manage_status')
            ->group(function () {
                Route::get('/stats', PlatformStatsController::class);
                Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);

                Route::get('/merchants', [MerchantController::class, 'index']);
                Route::get('/merchants/{merchant}', [MerchantController::class, 'show']);
                Route::post('/merchants/{merchant}/activate', [MerchantController::class, 'activate']);
                Route::post('/merchants/{merchant}/reject', [MerchantController::class, 'reject']);
                Route::post('/merchants/{merchant}/suspend', [MerchantController::class, 'suspend']);
                Route::post('/merchants/{merchant}/resend-invitation', [MerchantController::class, 'resendInvitation']);
                Route::put('/merchants/{merchant}/subscription', [MerchantController::class, 'assignPlan']);
            });
    });
});
