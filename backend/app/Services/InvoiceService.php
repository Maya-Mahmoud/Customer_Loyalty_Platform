<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Loyalty\CycleSnapshot;
use App\Services\Loyalty\LoyaltyEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording a sale (BRD 8.4) — the operation the whole system exists to support,
 * and the one performed most often.
 *
 * The invoice and its ledger entry are written as one unit. If the entry failed
 * after the invoice was stored, the customer's balance would be permanently short
 * by that sale and nothing would reveal which one, so partial success is not an
 * outcome worth allowing.
 *
 * Three cases produce an invoice with no ledger entry, deliberately:
 *
 *  - no customer attached (BR-022): the sale is recorded for the reports, but
 *    belongs to nobody and accumulates nothing;
 *  - below the rule's minimum (BR-003): recorded, and visibly not counted;
 *  - no rule published yet: nothing can be evaluated, so nothing is claimed.
 *
 * The ledger stays a record of movements only. An entry worth zero would still be
 * a movement, and reconciling the balance would have to filter it out.
 */
class InvoiceService
{
    public function __construct(
        private readonly LoyaltyEngine $engine,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Saves the sale and returns what the rep needs to see next.
     *
     * @param  array<string, mixed>  $data
     * @return array{invoice: Invoice, snapshot: CycleSnapshot|null, counted: bool}
     */
    public function record(array $data, User $enteredBy): array
    {
        $customer = $this->resolveCustomer($data['customer_id'] ?? null);
        $branchId = $this->resolveBranchId($data['branch_id'] ?? null, $enteredBy);
        $amount = (float) $data['amount'];
        $invoiceDate = $data['invoice_date'];

        /*
         * The rule in force on the invoice date, not today's. A sale recorded a day
         * late is governed by the rule that applied when it happened (BR-015).
         */
        $rule = $customer !== null
            ? $this->engine->ruleFor($customer, $invoiceDate)
            : null;

        $counted = $customer !== null
            && $rule !== null
            && $this->engine->qualifies($rule, $amount);

        $invoice = DB::transaction(function () use ($data, $customer, $branchId, $amount, $invoiceDate, $enteredBy, $counted, $rule) {
            $invoice = $this->createInvoice([
                'branch_id' => $branchId,
                'user_id' => $enteredBy->getKey(),
                'customer_id' => $customer?->getKey(),
                'invoice_number' => $data['invoice_number'],
                'amount' => $amount,
                'invoice_date' => $invoiceDate,
                'qualifies_for_accumulation' => $counted,
            ]);

            if ($counted) {
                LedgerEntry::create([
                    'customer_id' => $customer->getKey(),
                    'branch_id' => $branchId,
                    'cycle_number' => $customer->current_cycle_number,
                    'type' => LedgerEntryType::Accrual,
                    'amount' => $amount,
                    'invoice_count_delta' => 1,
                    'source_type' => $invoice::class,
                    'source_id' => $invoice->getKey(),
                    'loyalty_rule_id' => $rule->getKey(),
                    'created_by' => $enteredBy->getKey(),
                ]);
            }

            // Cached rather than derived: the customer card shows it on every
            // lookup, and BR-017 measures inactivity from it.
            if ($customer !== null) {
                $customer->forceFill(['last_purchase_at' => now()])->save();
            }

            return $invoice;
        });

        $this->audit->record(
            action: 'invoice.recorded',
            entity: $invoice,
            after: $invoice->only(['invoice_number', 'amount', 'customer_id', 'branch_id', 'qualifies_for_accumulation']),
            actor: $enteredBy,
        );

        return [
            'invoice' => $invoice,
            // BRD FR-RED-01: eligibility is re-evaluated after every entry, so the
            // rep is told at the counter while the customer is still standing there.
            'snapshot' => $customer !== null
                ? $this->engine->snapshot($customer->fresh(), $rule, $branchId)
                : null,
            'counted' => $counted,
        ];
    }

    /**
     * Invoices of one customer, newest first — the history behind the customer card
     * (BRD FR-CUS-05, 8.5 step 2).
     */
    public function historyFor(Customer $customer, int $limit = 20)
    {
        // Corrections come along so the card can show a pending request and what
        // has already been returned (BRD 8.7).
        return Invoice::with(['branch', 'corrections'])
            ->where('customer_id', $customer->getKey())
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * What this user has entered today, for the strip beside the till.
     *
     * Deliberately narrow: their own entries, today only, and no customer names —
     * the amount, the time, and whether it counted. That is enough to catch a
     * mistyped figure or a sale entered twice, which is the whole purpose.
     *
     * It carries no customer names on purpose. BRD BR-019 keeps a rep from browsing
     * the customer base, and a list of recent customers beside the busiest screen in
     * the system would undo that more effectively than any export button.
     */
    public function recentFor(User $user, int $limit = 5)
    {
        return Invoice::query()
            ->where('user_id', $user->getKey())
            ->whereDate('created_at', now()->toDateString())
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'invoice_number', 'amount', 'status', 'qualifies_for_accumulation', 'created_at']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createInvoice(array $attributes): Invoice
    {
        $supplied = trim((string) ($attributes['invoice_number'] ?? '')) !== '';

        /*
         * An amendment to BRD FR-INV-01, which assumes the number is copied from the
         * paper receipt. It stays possible — and for a shop with a till that prints
         * one it is still the better answer, because a number shared with the receipt
         * is what ties a loyalty entry to a sale that demonstrably happened (AF-01).
         *
         * But most small shops write receipts by hand, and there the typed number
         * bought nothing while costing a field at the counter and every typo that
         * came with it. So the number is now optional: type the receipt number if
         * there is one, and the system numbers the sale if there is not.
         */
        if (! $supplied) {
            return $this->createNumbered($attributes);
        }

        try {
            return Invoice::create($attributes);
        } catch (UniqueConstraintViolationException) {
            /*
             * BRD BR-004 and AF-01: the number is unique inside the merchant, which
             * is what stops the same sale being entered twice to inflate a balance.
             *
             * Caught from the constraint rather than checked beforehand, because a
             * check-then-insert leaves a gap two tills can both pass through. The
             * index is the only thing that cannot be raced.
             */
            throw ValidationException::withMessages([
                'invoice_number' => __('This invoice number has already been recorded.'),
            ]);
        }
    }

    /**
     * Numbers the sale itself.
     *
     * Retried rather than locked: two tills asking for the next number at the same
     * instant both compute the same one, and exactly one of them loses the unique
     * index (BR-004). Losing means asking again, which is cheaper than a lock held
     * across a transaction on the busiest table in the system — and correct even if a
     * number is somehow taken by hand in between.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createNumbered(array $attributes): Invoice
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Invoice::create([
                    ...$attributes,
                    'invoice_number' => $this->nextNumber($attempt),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            'invoice_number' => __('Could not assign an invoice number. Please try again.'),
        ]);
    }

    /**
     * The next number in this merchant's own series, reset each year.
     *
     * Scoped by the tenant like every other query, so two shops both run from 1 and
     * neither can see the other's sequence. The year in the prefix keeps the numbers
     * short and makes a stack of them sortable by eye, which is what a shop owner
     * actually does with a list of invoice numbers.
     */
    private function nextNumber(int $offset = 0): string
    {
        $prefix = 'INV-' . now()->year . '-';

        $last = Invoice::query()
            ->where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $sequence = $last === null
            ? 0
            : (int) substr((string) $last, strlen($prefix));

        return $prefix . str_pad((string) ($sequence + 1 + $offset), 5, '0', STR_PAD_LEFT);
    }

    private function resolveCustomer(mixed $customerId): ?Customer
    {
        if ($customerId === null) {
            return null;
        }

        // Scoped, so an id from another merchant simply does not resolve.
        $customer = Customer::find($customerId);

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => __('This customer was not found.'),
            ]);
        }

        return $customer;
    }

    /**
     * A rep or branch manager records at their own branch, whatever the request
     * says — the branch is theirs, not the client's to choose (BRD FR-INV-03).
     * An owner spans every branch, so they must say which one.
     */
    private function resolveBranchId(mixed $requested, User $enteredBy): int
    {
        if ($enteredBy->branch_id !== null) {
            return $enteredBy->branch_id;
        }

        if ($requested === null) {
            throw ValidationException::withMessages([
                'branch_id' => __('Choose the branch this sale belongs to.'),
            ]);
        }

        return (int) $requested;
    }
}
