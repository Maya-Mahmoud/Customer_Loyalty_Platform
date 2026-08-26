<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UploadImageRequest;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use App\Services\ImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The signed-in user's own account.
 *
 * Everything here acts on the caller and never takes a user id, so there is no
 * request shape that edits somebody else — staff accounts are managed through
 * StaffController, behind users.manage. The two paths stay separate on purpose:
 * changing your own password should not require the permission to change anyone's.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly ImageStorage $images,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Name and phone. The email is not editable here: it identifies the account and
     * received the invitation, so changing it belongs to a verified flow of its own.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $original = $user->only(['name', 'phone']);

        $user->fill($request->validated())->save();

        $this->audit->recordChange('profile.updated', $user, $original);

        return response()->json([
            'message' => __('Your details have been saved.'),
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * BRD FR-SEC-01: the current password is required, so a stolen open session
     * cannot lock the owner out of their own account.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make($request->validated()['password']),
        ])->save();

        /*
         * Every other token goes. If the reason for changing a password was that
         * somebody else had it, leaving their session alive would defeat the change
         * — and the current device stays signed in so the user is not thrown out of
         * the screen they are standing on.
         */
        $current = $user->currentAccessToken();

        $user->tokens()
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();

        $this->audit->record(action: 'profile.password_changed', entity: $user, actor: $user);

        return response()->json([
            'message' => __('Your password has been changed. Other devices have been signed out.'),
        ]);
    }

    public function uploadAvatar(UploadImageRequest $request): JsonResponse
    {
        $user = $request->user();

        $path = $this->images->store($request->file('image'), 'avatars', $user->avatar_path);

        $user->forceFill(['avatar_path' => $path])->save();

        return response()->json([
            'message' => __('Your picture has been updated.'),
            'user' => UserResource::make($user),
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->images->delete($user->avatar_path);

        $user->forceFill(['avatar_path' => null])->save();

        return response()->json([
            'message' => __('Your picture has been removed.'),
            'user' => UserResource::make($user),
        ]);
    }
}
