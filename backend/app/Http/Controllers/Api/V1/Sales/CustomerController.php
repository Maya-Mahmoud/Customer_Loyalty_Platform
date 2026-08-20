<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\RegisterCustomerRequest;
use App\Http\Resources\CustomerCardResource;
use App\Models\Customer;
use App\Models\Redemption;
use App\Services\CustomerService;
use App\Services\InvoiceService;
use App\Services\Loyalty\LoyaltyEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer lookup and registration at the point of sale (BRD 8.4, 8.5).
 *
 * The customer never reaches any of this themselves — they have no account
 * (BR-001). Every route here is used by staff with the customer in front of them,
 * which is why the whole card comes back in one response: a second round trip is a
 * second wait at the counter.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly InvoiceService $invoices,
        private readonly LoyaltyEngine $engine,
    ) {
    }

    /**
     * BRD FR-CUS-04: instant lookup by mobile number.
     *
     * Answers 200 either way. A number with no record is a normal outcome — it is
     * how the rep learns to offer registration — not an error.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:4', 'max:20'],
        ]);

        $customer = $this->customers->findByPhone($request->string('phone')->toString());

        if ($customer === null) {
            return response()->json([
                'found' => false,
                'customer' => null,
            ]);
        }

        return response()->json([
            'found' => true,
            'customer' => $this->card($customer, $request),
        ]);
    }

    /**
     * BRD 8.4 step 4: created on the spot, with no action required from the
     * customer beyond speaking their name and number.
     */
    public function store(RegisterCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->register($request->validated(), $request->user());

        return response()->json([
            'message' => __('The customer has been registered.'),
            'customer' => $this->card($customer, $request),
        ], 201);
    }

    /**
     * The full card with purchase history — the lookup screen of BRD 8.5.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        return response()->json([
            'customer' => $this->card($customer, $request, withHistory: true),
        ]);
    }

    /**
     * Records or withdraws the customer's agreement to be contacted.
     *
     * Section 16 requires consent to be withdrawable at any time; since the
     * customer has no account, staff do it on their behalf when asked.
     */
    public function setConsent(Request $request, Customer $customer): JsonResponse
    {
        $request->validate(['consent_given' => ['required', 'boolean']]);

        $this->customers->setConsent($customer, $request->boolean('consent_given'), $request->user());

        return response()->json([
            'message' => __('The contact preference has been updated.'),
            'customer' => $this->card($customer->fresh(), $request),
        ]);
    }

    /**
     * Assembles the card. The accumulation is scoped to the caller's branch only
     * when the rule says so (BRD FR-LOY-05); under the merchant-wide default the
     * branch is irrelevant (BR-016).
     */
    private function card(Customer $customer, Request $request, bool $withHistory = false): CustomerCardResource
    {
        $snapshot = $this->engine->snapshot($customer, branchId: $request->user()->branch_id);

        if ($withHistory) {
            $customer->setRelation('invoices', $this->invoices->historyFor($customer));
        }

        return new CustomerCardResource(
            $customer,
            $snapshot,
            Redemption::where('customer_id', $customer->getKey())->count(),
        );
    }
}
