<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreUserRequest;
use App\Http\Requests\Staff\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff of the signed-in merchant (BRD 8.2, FR-BRN-02 to FR-BRN-06).
 *
 * Behind can:users.manage — the store owner only. The tenant scope keeps every
 * query and every route binding inside this merchant.
 */
class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff)
    {
    }

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::with('branch')->orderBy('role')->orderBy('name')->get()
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->staff->createUser($request->user()->merchant, $request->validated());

        return response()->json([
            'message' => __('The user has been added and invited to set their password.'),
            'data' => UserResource::make($user->load('branch')),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->staff->updateUser($user, $request->validated(), $request->user());

        return response()->json([
            'message' => __('The user has been updated.'),
            'data' => UserResource::make($user->load('branch')),
        ]);
    }

    public function setActive(User $user, string $state): JsonResponse
    {
        $active = $state === 'enable';

        $this->staff->setUserActive($user, $active, request()->user());

        return response()->json([
            'message' => $active ? __('The user is active again.') : __('The user has been disabled.'),
            'data' => UserResource::make($user->load('branch')),
        ]);
    }

    /**
     * For a user who never followed their invitation — a lost email, or an expired
     * link. The link goes to their address only and is never shown here.
     */
    public function resendInvitation(User $user): JsonResponse
    {
        $this->staff->resendInvitation($user);

        return response()->json([
            'message' => __('A new invitation has been emailed to the user.'),
            'data' => UserResource::make($user->load('branch')),
        ]);
    }
}
