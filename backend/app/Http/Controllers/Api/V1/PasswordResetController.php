<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

/**
 * Recovering a forgotten password with an emailed code.
 *
 * Two calls, one screen: ask for a code, then send it back together with the new
 * password. The applicant never leaves the page and never follows a link.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwords)
    {
    }

    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->request($request->string('email')->toString(), $request->ip());

        // Identical whether or not the address is registered, so this endpoint
        // cannot be used to discover accounts.
        return response()->json([
            'message' => __('If that address belongs to an account, a code is on its way.'),
            'expires_in_minutes' => (int) config('verification.ttl_minutes'),
        ]);
    }

    public function update(ResetPasswordRequest $request): JsonResponse
    {
        $user = $this->passwords->reset(
            email: $request->string('email')->toString(),
            code: $request->string('code')->toString(),
            password: $request->string('password')->toString(),
        );

        // Signed straight in: they just proved control of the mailbox, so asking
        // them to type the password they only just chose adds nothing.
        return response()->json([
            'message' => __('Your password has been changed.'),
            'token' => $user->createToken('web')->plainTextToken,
            'user' => UserResource::make($user->load(['merchant', 'branch'])),
        ]);
    }
}
