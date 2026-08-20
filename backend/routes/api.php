<?php

use App\Http\Controllers\Api\V1\Admin\MerchantController;
use App\Http\Controllers\Api\V1\Admin\PlatformStatsController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\Merchant\BranchController;
use App\Http\Controllers\Api\V1\Merchant\LoyaltyRuleController;
use App\Http\Controllers\Api\V1\Merchant\StaffController;
use App\Http\Controllers\Api\V1\MerchantRegistrationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\Sales\CorrectionController;
use App\Http\Controllers\Api\V1\Sales\CustomerController as SalesCustomerController;
use App\Http\Controllers\Api\V1\Sales\InvoiceController;
use App\Http\Controllers\Api\V1\Sales\RedemptionController;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

    Route::middleware(['auth:sanctum', 'account.active', 'tenant', SubstituteBindings::class])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);


        /*
         * The point of sale (BRD 8.4, 8.5) — used by every role that serves a
         * customer. Each gate is a row of BRD 7.2; the branch an entry belongs to
         * comes from the account, never from the request.
         */
        Route::middleware('can:customers.lookup')->group(function () {
            Route::get('/customers/lookup', [SalesCustomerController::class, 'lookup']);
            Route::get('/customers/{customer}', [SalesCustomerController::class, 'show']);
        });

        Route::middleware('can:customers.register')->group(function () {
            Route::post('/customers', [SalesCustomerController::class, 'store']);
            Route::put('/customers/{customer}/consent', [SalesCustomerController::class, 'setConsent']);
        });

        Route::middleware('can:invoices.create')->group(function () {
            Route::post('/invoices', [InvoiceController::class, 'store']);

            /*
             * BRD BR-012: raising a correction request needs only the right to
             * record a sale, so the person who made the mistake reports it. The
             * decision below needs invoices.amend.
             */
            Route::post('/invoices/{invoice}/corrections', [CorrectionController::class, 'store']);
        });

        // Deciding on a correction (BRD 8.7, FR-INV-08) — a manager or the owner.
        Route::middleware('can:invoices.amend')->group(function () {
            Route::get('/corrections', [CorrectionController::class, 'index']);
            Route::post('/corrections/{correction}/approve', [CorrectionController::class, 'approve']);
            Route::post('/corrections/{correction}/reject', [CorrectionController::class, 'reject']);
        });

        /*
         * Paying a reward out (BRD 8.6). By BRD 7.2 and BR-013 only a branch
         * manager or the owner holds this — a rep records sales but never hands
         * money back, which is the separation of duties of AF-08.
         */
        Route::middleware('can:redemptions.create')->group(function () {
            Route::get('/customers/{customer}/redemptions', [RedemptionController::class, 'index']);
            Route::get('/customers/{customer}/redemptions/preview', [RedemptionController::class, 'preview']);
            Route::post('/customers/{customer}/redemptions', [RedemptionController::class, 'store']);
        });
        /*
         * The store owner's own setup (BRD 8.2). Each gate comes straight from the
         * matrix of BRD 7.2, and the tenant scope keeps every query and every route
         * binding inside the signed-in merchant.
         */
        Route::middleware('can:branches.manage')->group(function () {
            Route::get('/branches', [BranchController::class, 'index']);
            Route::get('/branches/usage', [BranchController::class, 'usage']);
            Route::post('/branches', [BranchController::class, 'store']);
            Route::put('/branches/{branch}', [BranchController::class, 'update']);
            Route::post('/branches/{branch}/{state}', [BranchController::class, 'setActive'])
                ->whereIn('state', ['enable', 'disable']);
        });

        // The rule engine of BRD 8.3. No update route: a change publishes a new
        // version, because BR-015 forbids a rule taking effect retroactively.
        Route::middleware('can:loyalty_rules.manage')->group(function () {
            Route::get('/loyalty-rule', [LoyaltyRuleController::class, 'index']);
            Route::post('/loyalty-rule', [LoyaltyRuleController::class, 'store']);
        });

        Route::middleware('can:users.manage')->group(function () {
            Route::get('/staff', [StaffController::class, 'index']);
            Route::post('/staff', [StaffController::class, 'store']);
            Route::put('/staff/{user}', [StaffController::class, 'update']);
            Route::post('/staff/{user}/{state}', [StaffController::class, 'setActive'])
                ->whereIn('state', ['enable', 'disable']);
            Route::post('/staff/{user}/resend-invitation', [StaffController::class, 'resendInvitation']);
        });

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
