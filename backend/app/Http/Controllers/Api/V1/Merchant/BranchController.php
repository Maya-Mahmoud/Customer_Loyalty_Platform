<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\PlanLimitService;
use App\Services\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Branches of the signed-in merchant (BRD 8.2, FR-BRN-01).
 *
 * The route group carries can:branches.manage, which by the matrix of BRD 7.2 only
 * the store owner holds. Route binding is tenant-scoped, so a branch id belonging
 * to another merchant simply does not resolve.
 */
class BranchController extends Controller
{
    public function __construct(
        private readonly StaffService $staff,
        private readonly PlanLimitService $limits,
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        return BranchResource::collection(
            Branch::withCount('users')->orderBy('name')->get()
        );
    }

    /**
     * Usage sits next to the list so the owner sees the cap before hitting it
     * (BRD FR-ADM-04, 8.2 exception).
     */
    public function usage(): JsonResponse
    {
        return response()->json($this->limits->usage(request()->user()->merchant));
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = $this->staff->createBranch($request->user()->merchant, $request->validated());

        return response()->json([
            'message' => __('The branch has been added.'),
            'data' => BranchResource::make($branch->loadCount('users')),
        ], 201);
    }

    public function update(StoreBranchRequest $request, Branch $branch): JsonResponse
    {
        $this->staff->updateBranch($branch, $request->validated());

        return response()->json([
            'message' => __('The branch has been updated.'),
            'data' => BranchResource::make($branch->loadCount('users')),
        ]);
    }

    /**
     * Branches are switched off rather than deleted, so their invoices keep their
     * origin.
     */
    public function setActive(Branch $branch, string $state): JsonResponse
    {
        $active = $state === 'enable';

        $this->staff->setBranchActive($branch, $active);

        return response()->json([
            'message' => $active ? __('The branch is active again.') : __('The branch has been switched off.'),
            'data' => BranchResource::make($branch->loadCount('users')),
        ]);
    }
}
