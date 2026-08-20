<?php

namespace App\Services;

use App\Enums\CorrectionStatus;
use App\Enums\CorrectionType;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\InvoiceCorrection;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cancelling and returning invoices (BRD 8.7).
 *
 * Two rules shape everything here. BRD BR-010: an invoice is never deleted, only
 * marked cancelled, so the record of what was entered survives the correction.
 * BRD BR-009: the accumulation is undone by a reversing entry, never by editing the
 * original one — the ledger stays append-only and a balance can always be explained
 * by replaying it.
 *
 * BRD BR-012 separates the request from the decision: a rep who could cancel their
 * own entries could also erase the evidence of a false one, which is the pattern
 * AF-02 describes. A manager or the owner already holds the authority, so their own
 * request is applied at once rather than waiting for an approval from themselves.
 */
class CorrectionService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Raises a correction request, and applies it immediately when the person
     * raising it holds the authority to approve one.
     *
     * @param  array{type: string, amount?: float|string|null, reason: string}  $data
     */
    public function request(Invoice $invoice, array $data, User $requestedBy): InvoiceCorrection
    {
        $type = CorrectionType::from($data['type']);
        $amount = $type->needsAmount() ? (float) $data['amount'] : null;

        $this->guardInvoiceIsCorrectable($invoice);
        $this->guardNoPendingRequest($invoice);
        $this->guardReturnAmount($type, $amount, $invoice);

        $canApprove = $requestedBy->role->has(Permission::AmendInvoice);

        $correction = InvoiceCorrection::create([
            'invoice_id' => $invoice->getKey(),
            'type' => $type,
            'amount' => $amount,
            'reason' => trim($data['reason']),
            'status' => $canApprove ? CorrectionStatus::Approved : CorrectionStatus::Pending,
            'requested_by' => $requestedBy->getKey(),
            'reviewed_by' => $canApprove ? $requestedBy->getKey() : null,
            'reviewed_at' => $canApprove ? now() : null,
        ]);

        $this->audit->record(
            action: 'correction.requested',
            entity: $correction,
            after: [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'type' => $type->value,
                'amount' => $amount,
                'reason' => $correction->reason,
                'applied_immediately' => $canApprove,
            ],
            actor: $requestedBy,
        );

        if ($canApprove) {
            $this->apply($correction, $requestedBy);
        }

        return $correction;
    }

    /**
     * BRD FR-INV-08: the manager's decision is what moves the balance.
     */
    public function approve(InvoiceCorrection $correction, User $reviewer, ?string $note = null): InvoiceCorrection
    {
        $this->guardPending($correction);
        $this->guardInvoiceIsCorrectable($correction->invoice);

        $correction->forceFill([
            'status' => CorrectionStatus::Approved,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        $this->apply($correction, $reviewer);

        return $correction;
    }

    /**
     * A refused request leaves the invoice and the balance exactly as they were,
     * and stays on the record with its reason — a rejected request is evidence too.
     */
    public function reject(InvoiceCorrection $correction, User $reviewer, ?string $note = null): InvoiceCorrection
    {
        $this->guardPending($correction);

        $correction->forceFill([
            'status' => CorrectionStatus::Rejected,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        $this->audit->record(
            action: 'correction.rejected',
            entity: $correction,
            after: ['review_note' => $note],
            actor: $reviewer,
        );

        return $correction;
    }

    /**
     * Requests still waiting for a decision (BRD FR-INV-08). Branch-bound roles see
     * their own branch only, which is FR-BRN-03 applied to the queue.
     */
    public function pendingFor(User $user)
    {
        return InvoiceCorrection::with(['invoice.branch', 'invoice.customer', 'requestedBy'])
            ->pending()
            ->when(
                $user->role->isBranchBound() && $user->branch_id !== null,
                fn ($query) => $query->whereHas(
                    'invoice',
                    fn ($invoice) => $invoice->where('branch_id', $user->branch_id)
                )
            )
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Writes the reversing entry and marks the invoice, as one unit.
     *
     * @return array{reversed: float, cycle_number: int|null, after_redemption: bool}
     */
    private function apply(InvoiceCorrection $correction, User $actor): array
    {
        $invoice = $correction->invoice;
        $accrual = $this->accrualFor($invoice);

        $result = DB::transaction(function () use ($correction, $invoice, $accrual, $actor): array {
            $reversed = 0.0;
            $cycleNumber = null;
            $afterRedemption = false;

            /*
             * Nothing to reverse when the invoice never accumulated: no customer
             * (BR-022), an amount under the minimum (BR-003), or no rule published
             * at the time. The invoice is still marked, because the sale itself is
             * being cancelled.
             */
            if ($accrual !== null) {
                $customer = $invoice->customer;
                $cycleNumber = $this->targetCycle($accrual->cycle_number, $customer->current_cycle_number);
                $afterRedemption = $cycleNumber !== $accrual->cycle_number;

                /*
                 * Capped at what is still standing. A partial return followed by a
                 * cancellation must not reverse the same money twice, and the
                 * ledger is the only place that knows what has already gone back.
                 */
                $reversed = min($correction->reversalAmount(), $this->unreversedAmount($invoice, $accrual));

                if ($reversed <= 0) {
                    // Everything has already been returned; the invoice is still
                    // marked below, but there is no movement left to record.
                    return [
                        'reversed' => 0.0,
                        'cycle_number' => null,
                        'after_redemption' => false,
                    ];
                }

                LedgerEntry::create([
                    'customer_id' => $customer->getKey(),
                    'branch_id' => $accrual->branch_id,
                    'cycle_number' => $cycleNumber,
                    'type' => LedgerEntryType::Reversal,
                    'amount' => -$reversed,
                    /*
                     * A partial return does not undo the visit: the customer did
                     * come in and did buy something, so the invoice still counts
                     * once towards a visit threshold. A cancellation or a full
                     * return takes the visit back with the money.
                     */
                    'invoice_count_delta' => $correction->type->needsAmount()
                        ? 0
                        : -$accrual->invoice_count_delta,
                    'source_type' => $correction::class,
                    'source_id' => $correction->getKey(),
                    'loyalty_rule_id' => $accrual->loyalty_rule_id,
                    'created_by' => $actor->getKey(),
                    'note' => $afterRedemption
                        ? __('Reversal of a cycle that had already been redeemed; carried into the open cycle.')
                        : null,
                ]);
            }

            /*
             * A partial return leaves the invoice active: part of the sale stands,
             * and the returned part lives in the correction and its entry. Rewriting
             * the stored amount would erase what was actually keyed in (BR-010).
             */
            if (! $correction->type->needsAmount()) {
                $invoice->forceFill([
                    'status' => InvoiceStatus::Cancelled,
                    'cancelled_at' => now(),
                ])->save();
            }

            return [
                'reversed' => $reversed,
                'cycle_number' => $cycleNumber,
                'after_redemption' => $afterRedemption,
            ];
        });

        $this->audit->record(
            action: 'correction.applied',
            entity: $correction,
            after: [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'type' => $correction->type->value,
                'reversed_amount' => $result['reversed'],
                'cycle_number' => $result['cycle_number'],
                /*
                 * The one fact worth being able to find later: the correction landed
                 * on a cycle other than the one the invoice belonged to, because that
                 * cycle had already been paid out.
                 */
                'after_redemption' => $result['after_redemption'],
                'reason' => $correction->reason,
            ],
            actor: $actor,
        );

        return $result;
    }

    /**
     * Which cycle the reversal belongs to — the open question OD-06 leaves.
     *
     * If the invoice's cycle is still open, the reversal belongs where the accrual
     * was and the customer simply moves back down the progress bar.
     *
     * If that cycle was already redeemed, the discount has been consumed and there
     * is nothing to take back: asking a customer to return a discount over a
     * cancelled invoice from weeks ago costs more goodwill than the amount involved,
     * and the platform cannot reach into a till anyway. So the closed cycle is left
     * settled and the reversal lands on the cycle that is open now, which may push
     * it below zero until the customer buys again. The audit entry records that this
     * is what happened, so a manager can see it rather than deduce it.
     */
    private function targetCycle(int $accrualCycle, int $currentCycle): int
    {
        return $accrualCycle === $currentCycle ? $accrualCycle : $currentCycle;
    }

    /**
     * The entry the invoice created, if it created one.
     */
    private function accrualFor(Invoice $invoice): ?LedgerEntry
    {
        return LedgerEntry::where('source_type', $invoice::class)
            ->where('source_id', $invoice->getKey())
            ->where('type', LedgerEntryType::Accrual)
            ->first();
    }

    /**
     * How much of the invoice is still standing in the ledger: what it accrued,
     * less everything earlier corrections have already sent back.
     */
    private function unreversedAmount(Invoice $invoice, LedgerEntry $accrual): float
    {
        $correctionIds = InvoiceCorrection::where('invoice_id', $invoice->getKey())
            ->pluck('id');

        $reversed = (float) LedgerEntry::where('source_type', InvoiceCorrection::class)
            ->whereIn('source_id', $correctionIds)
            ->where('type', LedgerEntryType::Reversal)
            ->sum('amount');

        // Reversals are stored negative, so their sum is subtracted by adding it.
        return max(0.0, (float) $accrual->amount + $reversed);
    }

    private function guardInvoiceIsCorrectable(Invoice $invoice): void
    {
        if ($invoice->isCancelled()) {
            throw new ConflictHttpException(__('This invoice has already been cancelled.'));
        }
    }

    /**
     * One open request at a time. Two pending requests on one invoice would let two
     * approvals reverse the same sale twice.
     */
    private function guardNoPendingRequest(Invoice $invoice): void
    {
        $exists = InvoiceCorrection::where('invoice_id', $invoice->getKey())
            ->pending()
            ->exists();

        if ($exists) {
            throw new ConflictHttpException(
                __('A correction request for this invoice is already awaiting a decision.')
            );
        }
    }

    private function guardPending(InvoiceCorrection $correction): void
    {
        if (! $correction->isPending()) {
            throw new ConflictHttpException(__('This request has already been decided.'));
        }
    }

    /**
     * BRD FR-INV-07: a partial return cannot exceed the invoice. A return of the
     * whole amount is a full return, and is asked for as one so the invoice is
     * marked cancelled rather than left standing at zero.
     */
    private function guardReturnAmount(CorrectionType $type, ?float $amount, Invoice $invoice): void
    {
        if (! $type->needsAmount()) {
            return;
        }

        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('Enter the amount being returned.'),
            ]);
        }

        if ($amount >= (float) $invoice->amount) {
            throw ValidationException::withMessages([
                'amount' => __('A partial return must be less than the invoice. Use a full return instead.'),
            ]);
        }
    }
}
