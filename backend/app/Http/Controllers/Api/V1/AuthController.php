<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token-based sign-in for staff (BRD 7.1). The end customer never signs in
 * anywhere in this system (BR-001, BR-024).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // One message for a wrong email and a wrong password alike, so the
        // response cannot be used to discover which addresses exist.
        if ($user === null || ! Hash::check($request->string('password'), (string) $user->password)) {
            $this->audit->record('auth.login_failed', after: ['email' => $request->input('email')]);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        // A suspended merchant or a disabled user is refused here as well as on
        // every later request (BRD FR-ADM-03).
        if (! $user->canSignIn()) {
            $this->audit->record('auth.login_blocked', actor: $user, after: [
                'user_status' => $user->status->value,
                'merchant_status' => $user->merchant?->status->value,
            ]);

            throw ValidationException::withMessages([
                'email' => __('This account is not active. Please contact your store owner.'),
            ]);
        }

        // A fresh sign-in should not inherit tokens from an old one on the same
        // device name, otherwise revoked sessions could linger.
        $user->tokens()->where('name', $request->deviceName())->delete();

        $token = $user->createToken($request->deviceName())->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('auth.login', entity: $user, actor: $user);

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user->load(['merchant', 'branch'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('auth.logout', entity: $request->user());

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => __('Signed out successfully.'),
        ]);
    }

    /**
     * Rehydrates the session after a page reload; the Angular guard calls this
     * before letting the router settle on a protected route.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user()->load(['merchant', 'branch'])),
        ]);
    }
}
