<?php

namespace App\Console\Commands;

use App\Enums\MerchantStatus;
use App\Models\Merchant;
use App\Services\Loyalty\BalanceExpiryService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * BRD BR-017: write off accumulations nobody came back for.
 *
 * Runs merchant by merchant with the tenant context pinned, the same way a request
 * runs, so the expiry queries are scoped by the same global scope as everything else
 * — a job that read across merchants would be the one place in the system where the
 * isolation did not hold.
 *
 * Suspended and pending merchants are skipped. Expiring a balance is an act on a
 * live programme, and doing it to a store that currently has no access would mean
 * their customers lose balances while nobody can even see the screen.
 */
class ExpireStaleBalances extends Command
{
    protected $signature = 'balances:expire {--dry-run : Report what would expire without writing anything}';

    protected $description = 'Expire customer balances left untouched beyond the merchant validity window (BR-017)';

    public function handle(BalanceExpiryService $expiry, TenantContext $tenant): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Dry run: nothing will be written.');
        }

        $merchants = Merchant::withoutGlobalScopes()
            ->where('status', MerchantStatus::Active)
            ->orderBy('id')
            ->get(['id', 'name']);

        $customers = 0;
        $amount = 0.0;

        foreach ($merchants as $merchant) {
            $result = $tenant->for($merchant->id, fn () => $expiry->run($dryRun));

            if ($result['expired'] === 0) {
                continue;
            }

            $customers += $result['expired'];
            $amount += $result['amount'];

            $this->line("{$merchant->name}: {$result['expired']} customer(s), {$result['amount']} written off");
        }

        $this->info(sprintf('%d balance(s) expired, %s in total.', $customers, number_format($amount, 2, '.', '')));

        return self::SUCCESS;
    }
}
