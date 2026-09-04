<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomerSelfServiceLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's own balance at one shop (BRD FR-CUS-12).
 *
 * Public, because the customer has no account (BR-001). What replaces a login is a
 * receipt: the shop, the number they are registered under, and the invoice number
 * printed on their own receipt — or, when that is lost, the date and amount of their
 * last purchase.
 *
 * One response for every failure. "No such customer", "wrong invoice number" and
 * "that shop is suspended" all answer identically, because a reply that told them
 * apart would turn this into a way to discover who shops where — which is the thing
 * the proof exists to prevent.
 *
 * Rate limited hard in the route, not here: invoice numbers run in sequence, so
 * somebody holding one receipt could otherwise guess their neighbours'.
 */
class BalanceLookupController extends Controller
{
    public function __construct(private readonly CustomerSelfServiceLookup $lookup)
    {
    }

    /**
     * The shops a customer can name. Their names and cities are on their signs.
     */
    public function stores(): JsonResponse
    {
        return response()->json(['data' => $this->lookup->stores()]);
    }

    public function show(Request $request): JsonResponse
    {
        $claim = $request->validate([
            'merchant_id' => ['required', 'integer'],
            'phone' => ['required', 'string', 'min:8', 'max:20', 'regex:/^\+?[\d\s-]+$/'],

            // The receipt number, or the pair that stands in for a lost receipt.
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'invoice_date' => ['nullable', 'date', 'before_or_equal:today'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $card = $this->lookup->verify($claim);

        if ($card === null) {
            return response()->json([
                'message' => __('We could not match those details. Check the shop, the number and the invoice.'),
            ], 404);
        }

        return response()->json(['data' => $card]);
    }
}
