<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Enums\CorrectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreCorrectionRequest;
use App\Http\Resources\InvoiceCorrectionResource;
use App\Models\Invoice;
use App\Models\InvoiceCorrection;
use App\Services\CorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cancelling and returning invoices (BRD 8.7).
 *
 * Raising a request needs only the right to record a sale — the person who made the
 * mistake is the one who reports it. Deciding needs invoices.amend, which by BRD 7.2
 * is a manager or the owner. That split is BR-012, and it is the reason a rep cannot
 * quietly undo their own entry.
 */
class CorrectionController extends Controller
{
    public function __construct(private readonly CorrectionService $corrections)
    {
    }

    /**
     * The pending queue (BRD FR-INV-08).
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => InvoiceCorrectionResource::collection(
                $this->corrections->pendingFor($request->user())
            ),
        ]);
    }

    public function store(StoreCorrectionRequest $request, Invoice $invoice): JsonResponse
    {
        $correction = $this->corrections->request($invoice, $request->validated(), $request->user());

        $applied = $correction->status === CorrectionStatus::Approved;

        return response()->json([
            'message' => $applied
                ? __('The invoice has been cancelled and the balance corrected.')
                : __('The request has been sent to the branch manager for a decision.'),
            'data' => InvoiceCorrectionResource::make($correction->load(['invoice.branch', 'requestedBy'])),
            'applied' => $applied,
        ], 201);
    }

    public function approve(Request $request, InvoiceCorrection $correction): JsonResponse
    {
        $this->corrections->approve(
            $correction,
            $request->user(),
            $request->input('review_note'),
        );

        return response()->json([
            'message' => __('The request has been approved and the balance corrected.'),
            'data' => InvoiceCorrectionResource::make(
                $correction->load(['invoice.branch', 'requestedBy', 'reviewedBy'])
            ),
        ]);
    }

    public function reject(Request $request, InvoiceCorrection $correction): JsonResponse
    {
        $this->corrections->reject(
            $correction,
            $request->user(),
            $request->input('review_note'),
        );

        return response()->json([
            'message' => __('The request has been rejected and nothing has changed.'),
            'data' => InvoiceCorrectionResource::make(
                $correction->load(['invoice.branch', 'requestedBy', 'reviewedBy'])
            ),
        ]);
    }
}
