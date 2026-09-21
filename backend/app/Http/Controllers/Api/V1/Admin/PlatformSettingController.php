<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Http\Requests\Profile\UploadImageRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Services\AuditLogger;
use App\Services\ImageStorage;
use Illuminate\Http\JsonResponse;

/**
 * The platform's own settings (BRD FR-ADM-04, and section 5 on the plans).
 *
 * The currency and the price list are read and returned together because they are
 * one thing to a reader: a figure of 250 means nothing until you know what money it
 * is in. Fetching them separately would let the screen paint a price against last
 * request's currency for a frame, which on a page whose whole purpose is money is
 * not a cosmetic problem.
 *
 * Every plan is listed here, inactive ones included, unlike the public list used at
 * registration. A retired plan still has shops on it and the supervisor still has to
 * be able to see — and correct — what those shops are being charged.
 */
class PlatformSettingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ImageStorage $images,
    ) {
    }

    public function show(): JsonResponse
    {
        return $this->settings();
    }

    /**
     * The platform's own mark (the counterpart of FR-MER-06 for the platform).
     *
     * Reuses the same storage the shops' logos go through, which re-encodes every
     * upload rather than storing the bytes it was handed — the security control is
     * the same whoever is uploading.
     */
    public function uploadLogo(UploadImageRequest $request): JsonResponse
    {
        $previous = PlatformSetting::get(PlatformSetting::LOGO_PATH);

        $path = $this->images->store($request->file('image'), 'platform', $previous);

        PlatformSetting::set(PlatformSetting::LOGO_PATH, $path);

        $this->audit->record(action: 'platform.logo_updated');

        return $this->settings(__('The platform logo has been updated.'));
    }

    public function deleteLogo(): JsonResponse
    {
        $this->images->delete(PlatformSetting::get(PlatformSetting::LOGO_PATH));

        PlatformSetting::set(PlatformSetting::LOGO_PATH, '');

        $this->audit->record(action: 'platform.logo_removed');

        return $this->settings(__('The platform logo has been removed.'));
    }

    public function update(UpdatePlatformSettingsRequest $request): JsonResponse
    {
        $before = PlatformSetting::billingCurrency();
        $after = $request->validated()['billing_currency'];

        PlatformSetting::set(PlatformSetting::BILLING_CURRENCY, $after);

        /*
         * Logged even when it did not change. This is the setting that decides what
         * money every shop on the platform is billed in, and "who set it, and when"
         * is a question that gets asked after an invoice is disputed — by which time
         * an unwritten row cannot be recovered.
         */
        $this->audit->record(
            action: 'platform.billing_currency_changed',
            before: ['billing_currency' => $before],
            after: ['billing_currency' => $after],
        );

        return $this->settings(__('The platform settings have been saved.'));
    }

    private function settings(?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'data' => [
                'billing_currency' => PlatformSetting::billingCurrency(),
                'logo_url' => PlatformSetting::logoUrl(),
                'currencies' => config('clp.currencies'),
                'plans' => SubscriptionPlanResource::collection(
                    SubscriptionPlan::orderBy('monthly_price')->get()
                ),
            ],
        ]));
    }
}
