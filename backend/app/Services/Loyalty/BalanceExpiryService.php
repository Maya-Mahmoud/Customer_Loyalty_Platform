<?php

namespace App\Services\Loyalty;

use App\Enums\LedgerEntryType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Writing off a balance nobody came back for (BRD BR-017).
 *
 * The rule of BRD FR-LOY-08 lets an owner say how long an accumulation stays alive.
 * Until now that number was stored and never acted on, which is worse than not
 * offering it: the owner believes old balances lapse, the customer believes they do
 * not, and the ledger agrees with the customer.
 *
 * Expiry is a ledger entry like everything else (BR-008), so a customer asking why
 * their balance is gone gets an answer with a date on it rather than a shrug. The
 * entry carries the negative of the open cycle and takes the invoice count with it —
 * a cycle half expired would be a cycle nobody could explain.
 *
 * What it deliberately does not touch: a paid reward, a voucher, or a closed cycle.
 * Only what is still sitting in the open cycle can lapse.
 */
class BalanceExpiryService
{
    public function __construct(
        private readonly LoyaltyEngine $engine,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Expires what has gone stale for one merchant.
     *
     * The merchant must already be pinned in the tenant context, exactly as a
     * request would pin it, so every query here is scoped the same way the rest of
     * the system is scoped.
     *
     * @return array{expired: int, amount: float}
     */
    public function run(bool $dryRun = false): array
    {
        $merchant = Merchant::withoutGlobalScopes()->find($this->tenant->id());

        $rule = $merchant?->ruleEffectiveOn();

        // No rule, or an owner who chose never to expire anything.
        if ($rule === null || $rule->balance_validity_months === null) {
            return ['expired' => 0, 'amount' => 0.0];
        }

        $cutoff = now()->subMonths($rule->balance_validity_months);

        $expired = 0;
        $total = 0.0;

        /*
         * Chunked, because this runs across every customer of every merchant and a
         * store with years of history should not be loaded into memory to be
         * counted. Ordered by id so chunking is stable.
         */
        $this->staleCustomers($cutoff)->chunkById(200, function ($customers) use ($rule, &$expired, &$total, $dryRun) {
            foreach ($customers as $customer) {
                $snapshot = $this->engine->snapshot($customer, $rule);

                if ($snapshot === null) {
                    continue;
                }

                // Nothing to write off, and an entry worth zero is not a movement.
                if ($snapshot->totalAmount <= 0 && $snapshot->invoiceCount <= 0) {
                    continue;
                }

                $expired++;
                $total += $snapshot->totalAmount;

                if ($dryRun) {
                    continue;
                }

                $this->writeOff($customer, $snapshot, $rule->balance_validity_months);
            }
        });

        return ['expired' => $expired, 'amount' => round($total, 2)];
    }

    /**
     * Customers whose last purchase is older than the window. Someone who never
     * bought anything has nothing to expire, so they are left out rather than
     * treated as infinitely stale.
     */
    private function staleCustomers(\Carbon\CarbonInterface $cutoff)
    {
        return Customer::query()
            ->whereNotNull('last_purchase_at')
            ->where('last_purchase_at', '<', $cutoff)
            ->whereNull('anonymized_at')
            ->orderBy('id');
    }

    /**
     * The write-off itself, and the cycle number moves on with it.
     *
     * Advancing the cycle is what stops the same balance being expired twice: the
     * expiry entry belongs to the cycle it closed, and the customer starts the next
     * one empty. It is the same claim the redemption flow makes, for the same
     * reason.
     */
    private function writeOff(Customer $customer, CycleSnapshot $snapshot, int $months): void
    {
        $closingCycle = $snapshot->cycleNumber;

        DB::transaction(function () use ($customer, $snapshot, $closingCycle, $months) {
            $claimed = Customer::whereKey($customer->getKey())
                ->where('current_cycle_number', $closingCycle)
                ->update([
                    'current_cycle_number' => $closingCycle + 1,
                    'updated_at' => now(),
                ]);

            // Someone redeemed or expired this cycle between the read and the write.
            if ($claimed === 0) {
                return;
            }

            LedgerEntry::create([
                'customer_id' => $customer->getKey(),
                // The branch the balance was last touched at, so a per-branch rule
                // still reconciles. There is no "no branch" option on the column.
                'branch_id' => $this->lastBranchFor($customer, $closingCycle),
                'cycle_number' => $closingCycle,
                'type' => LedgerEntryType::Expiry,
                'amount' => -$snapshot->totalAmount,
                'invoice_count_delta' => -$snapshot->invoiceCount,
                'loyalty_rule_id' => $snapshot->rule->getKey(),
                'note' => __('Expired after :months months without a purchase.', ['months' => $months]),
            ]);
        });
    }

    /**
     * Where the customer last accumulated. Falls back to any branch of the merchant
     * so an expiry is never blocked by a missing reference.
     */
    private function lastBranchFor(Customer $customer, int $cycleNumber): int
    {
        $branchId = LedgerEntry::query()
            ->where('customer_id', $customer->getKey())
            ->forCycle($cycleNumber)
            ->orderByDesc('id')
            ->value('branch_id');

        return (int) ($branchId
            ?? $customer->registered_at_branch_id
            ?? Branch::query()->value('id'));
    }
}
