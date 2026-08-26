<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerAnonymizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Erasing a customer at their request (BRD FR-CUS-10, section 16).
 *
 * A POST rather than a DELETE, because nothing is deleted: the sales stay, the
 * ledger stays, and the person is removed from them. Calling it DELETE would
 * describe the wrong operation to anyone reading the route list.
 */
class CustomerErasureController extends Controller
{
    public function __construct(private readonly CustomerAnonymizationService $anonymization)
    {
    }

    public function __invoke(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            // The written request is what the merchant answers with if asked why a
            // record was stripped; it is kept in the audit trail.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $result = $this->anonymization->anonymize($customer, $request->user(), $validated['reason']);

        return response()->json([
            'message' => __('The customer has been anonymised. The sales record is unchanged.'),
            'balance_written_off' => $result['balance_written_off'],
            'vouchers_cancelled' => $result['vouchers_cancelled'],
        ]);
    }
}
