<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\RegisterMerchantRequest;
use App\Http\Requests\Registration\ResendCodeRequest;
use App\Http\Requests\Registration\VerifyRegistrationRequest;
use App\Services\MerchantRegistrationService;
use Illuminate\Http\JsonResponse;

/**
 * Public endpoints of BRD 8.1: a store owner registers themselves, proves the
 * email address, and then waits for the supervisor's decision.
 *
 * Nothing here grants any access; the account stays inert until FR-ADM-02
 * activation.
 */
class MerchantRegistrationController extends Controller
{
    public function __construct(private readonly MerchantRegistrationService $registrations)
    {
    }

    public function store(RegisterMerchantRequest $request): JsonResponse
    {
        $merchant = $this->registrations->submit($request->merchantData(), $request->ip());

        return response()->json([
            'message' => __('We sent a verification code to your email address.'),
            'email' => $merchant->email,
            'expires_in_minutes' => (int) config('verification.ttl_minutes'),
        ], 201);
    }

    public function verify(VerifyRegistrationRequest $request): JsonResponse
    {
        $merchant = $this->registrations->verify(
            email: $request->string('email')->toString(),
            code: $request->string('code')->toString(),
        );

        return response()->json([
            'message' => __('Your request has been submitted and is awaiting review. We will email you the decision.'),
            'status' => $merchant->status->value,
        ]);
    }

    public function resend(ResendCodeRequest $request): JsonResponse
    {
        $this->registrations->resend(
            email: $request->string('email')->toString(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'message' => __('A new code is on its way.'),
        ]);
    }
}
