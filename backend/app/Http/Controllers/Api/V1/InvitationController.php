<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lets an invited user set their own password (BRD FR-BRN-04). Public, because the
 * invitee has no credentials yet — the token in the link is the authorisation.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Checked before the form renders, so an expired link says so instead of
     * failing only after the user has typed a password twice.
     */
    public function show(string $token): JsonResponse
    {
        $user = $this->invitations->resolve($token);

        if ($user === null) {
            throw new NotFoundHttpException(__('This link is invalid or has expired.'));
        }

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'merchant_name' => $user->merchant?->name,
        ]);
    }

    /**
     * Signs the user straight in afterwards: they just proved control of the
     * mailbox the link was sent to, and it saves an immediate second login.
     */
    public function store(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $user = $this->invitations->accept($token, $request->string('password')->toString());

        $this->audit->record('user.invitation_accepted', entity: $user, actor: $user);

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => UserResource::make($user->load(['merchant', 'branch'])),
        ]);
    }
}
