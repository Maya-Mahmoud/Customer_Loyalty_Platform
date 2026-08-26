<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateStoreProfileRequest;
use App\Http\Requests\Profile\UploadImageRequest;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use App\Services\AuditLogger;
use App\Services\ImageStorage;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The store's own profile (BRD FR-MER-05, FR-MER-06).
 *
 * The merchant is taken from the pinned tenant, never from the URL, so there is no
 * request shape that edits another store — the platform console is where a
 * supervisor acts on somebody else's record, behind its own gate.
 *
 * The commercial register and the email are not editable here. They identify the
 * business, they were verified at registration (BRD 8.1), and a screen that let an
 * owner rewrite them would make that verification meaningless.
 */
class StoreProfileController extends Controller
{
    public function __construct(
        private readonly ImageStorage $images,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => MerchantResource::make($this->merchant())]);
    }

    public function update(UpdateStoreProfileRequest $request): JsonResponse
    {
        $merchant = $this->merchant();
        $original = $merchant->only(['trade_name', 'city', 'phone', 'currency']);

        $merchant->fill($request->validated())->save();

        $this->audit->recordChange('merchant.profile_updated', $merchant, $original);

        return response()->json([
            'message' => __('The store details have been saved.'),
            'data' => MerchantResource::make($merchant),
        ]);
    }

    public function uploadLogo(UploadImageRequest $request): JsonResponse
    {
        $merchant = $this->merchant();

        $path = $this->images->store($request->file('image'), 'logos', $merchant->logo_path);

        $merchant->forceFill(['logo_path' => $path])->save();

        $this->audit->record(action: 'merchant.logo_updated', entity: $merchant);

        return response()->json([
            'message' => __('The store logo has been updated.'),
            'data' => MerchantResource::make($merchant),
        ]);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $merchant = $this->merchant();

        $this->images->delete($merchant->logo_path);

        $merchant->forceFill(['logo_path' => null])->save();

        $this->audit->record(action: 'merchant.logo_removed', entity: $merchant);

        return response()->json([
            'message' => __('The store logo has been removed.'),
            'data' => MerchantResource::make($merchant),
        ]);
    }

    /**
     * Merchants are the tenant root, so they carry no merchant_id to be scoped by;
     * the pinned id is what keeps this to the caller's own store.
     */
    private function merchant(): Merchant
    {
        return Merchant::withoutGlobalScopes()->findOrFail($this->tenant->id());
    }
}
