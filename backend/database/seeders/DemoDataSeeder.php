<?php

namespace Database\Seeders;

use App\Enums\ConsentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Loyalty\RedemptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A shop that looks like a shop.
 *
 * DatabaseSeeder builds the structure — plans, merchants, branches, staff, a rule.
 * This one fills one of those shops with trade, because a structure with no trade in
 * it shows empty reports, a flat chart and a customer list of one, which is not what
 * the system does and not what anyone should be shown.
 *
 * Every sale goes through InvoiceService and every redemption through
 * RedemptionService, the same paths the application itself uses. Writing the rows by
 * hand would be quicker and would produce a ledger that agrees with nothing: the
 * balances on screen are derived from these entries, so an entry invented here that
 * the engine would not have written is a number the screen cannot explain.
 *
 * The customers are spread deliberately across the states the rule can put someone
 * in — eligible now, nearly there, mid-cycle, redeemed once already, brand new — so
 * whoever is looking can see each of them without hunting for an example.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Safe to run repeatedly: it clears the demo shop's own trade first. It touches
 * nothing outside that one shop.
 */
class DemoDataSeeder extends Seeder
{
    /** The shop that gets the trade. The other seeded shop stays empty on purpose:
        an empty second tenant is what makes isolation visible. */
    private const SHOP = 'CR-100200';

    public function run(): void
    {
        $merchant = Merchant::where('commercial_register', self::SHOP)->first();

        if ($merchant === null) {
            $this->command->error('Run the main seeder first: php artisan db:seed');

            return;
        }

        $rep = $this->userFor($merchant, UserRole::SalesRep);
        $manager = $this->userFor($merchant, UserRole::BranchManager);

        if ($rep === null || $manager === null) {
            $this->command->error('The demo shop has no staff. Run: php artisan db:seed');

            return;
        }

        $this->clearTrade($merchant);

        $invoices = new InvoiceService(
            app(\App\Services\Loyalty\LoyaltyEngine::class),
            app(\App\Services\AuditLogger::class),
        );

        $number = 1;

        foreach ($this->people() as $person) {
            $customer = $this->customer($merchant, $rep, $person);

            foreach ($person['sales'] as $sale) {
                $invoices->record([
                    'customer_id' => $customer->getKey(),
                    'branch_id' => $rep->branch_id,
                    'invoice_number' => 'INV-'.str_pad((string) $number++, 5, '0', STR_PAD_LEFT),
                    'amount' => $sale['amount'],
                    'invoice_date' => $this->daysAgo($sale['days_ago']),
                ], $rep);
            }

            /*
             * Backdated after the fact. InvoiceService stamps the moment the sale was
             * entered, which is correct in a shop and wrong here, where every sale is
             * entered in the same second but happened over months.
             */
            $this->backdate($customer, $person['sales']);

            if ($person['redeemed'] ?? false) {
                app(RedemptionService::class)->redeem($customer, $manager);
            }
        }

        $this->command->info('Demo trade seeded for '.$merchant->name.'.');
        $this->command->info('Customers: '.Customer::where('merchant_id', $merchant->id)->count());
        $this->command->info('Invoices:  '.Invoice::whereHas('branch', fn ($q) => $q->where('merchant_id', $merchant->id))->count());
    }

    /**
     * Real-looking Syrian customers, and a spread of sales that puts each of them
     * somewhere different against a 1,000 threshold with a 10 minimum per invoice.
     *
     * @return list<array{name: string, phone: string, sales: list<array{amount: float, days_ago: int}>, redeemed?: bool}>
     */
    private function people(): array
    {
        return [
            // Eligible right now — the redemption flow has someone to run on.
            [
                'name' => 'سامر الحلبي',
                'phone' => '0933214567',
                'sales' => [
                    ['amount' => 240.00, 'days_ago' => 54],
                    ['amount' => 185.50, 'days_ago' => 41],
                    ['amount' => 312.00, 'days_ago' => 27],
                    ['amount' => 148.75, 'days_ago' => 12],
                    ['amount' => 205.00, 'days_ago' => 3],
                ],
            ],

            // Nearly there — the progress bar has something to show.
            [
                'name' => 'ريم العبد الله',
                'phone' => '0944876123',
                'sales' => [
                    ['amount' => 320.00, 'days_ago' => 38],
                    ['amount' => 275.25, 'days_ago' => 19],
                    ['amount' => 258.00, 'days_ago' => 6],
                ],
            ],

            // Has redeemed once already: history, and a carried-over balance.
            [
                'name' => 'مازن قاسم',
                'phone' => '0955341892',
                'sales' => [
                    ['amount' => 430.00, 'days_ago' => 88],
                    ['amount' => 385.00, 'days_ago' => 71],
                    ['amount' => 295.50, 'days_ago' => 63],
                    ['amount' => 140.00, 'days_ago' => 22],
                    ['amount' => 96.00, 'days_ago' => 9],
                ],
                'redeemed' => true,
            ],

            // Mid-cycle, steady.
            [
                'name' => 'لينا حدّاد',
                'phone' => '0966125478',
                'sales' => [
                    ['amount' => 165.00, 'days_ago' => 33],
                    ['amount' => 142.50, 'days_ago' => 17],
                    ['amount' => 98.00, 'days_ago' => 5],
                ],
            ],

            // One small sale below the 10 minimum, recorded and visibly not counted:
            // BR-003 on screen instead of in a document.
            [
                'name' => 'فادي مرعي',
                'phone' => '0988457213',
                'sales' => [
                    ['amount' => 210.00, 'days_ago' => 25],
                    ['amount' => 6.50, 'days_ago' => 14],
                    ['amount' => 175.00, 'days_ago' => 4],
                ],
            ],

            [
                'name' => 'هالة الخوري',
                'phone' => '0932674591',
                'sales' => [
                    ['amount' => 88.00, 'days_ago' => 29],
                    ['amount' => 124.00, 'days_ago' => 11],
                    ['amount' => 67.50, 'days_ago' => 2],
                ],
            ],

            [
                'name' => 'عمر الشامي',
                'phone' => '0947839215',
                'sales' => [
                    ['amount' => 395.00, 'days_ago' => 46],
                    ['amount' => 218.00, 'days_ago' => 8],
                ],
            ],

            // Registered today, one sale. Someone has to be new.
            [
                'name' => 'نور الدين صالح',
                'phone' => '0951472836',
                'sales' => [
                    ['amount' => 73.25, 'days_ago' => 1],
                ],
            ],
        ];
    }

    /**
     * Removes the demo shop's trade so the seeder can be run again without stacking
     * a second set of invoices on top of the first.
     *
     * Scoped to this shop's customers throughout. A blanket truncate would take the
     * other tenant's rows with it, which is the one thing this system is built not
     * to do.
     */
    private function clearTrade(Merchant $merchant): void
    {
        $customerIds = Customer::where('merchant_id', $merchant->id)->pluck('id');

        if ($customerIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($customerIds) {
            Redemption::whereIn('customer_id', $customerIds)->delete();
            LedgerEntry::whereIn('customer_id', $customerIds)->delete();
            Invoice::whereIn('customer_id', $customerIds)->forceDelete();
            Customer::whereIn('id', $customerIds)->forceDelete();
        });
    }

    private function customer(Merchant $merchant, User $rep, array $person): Customer
    {
        return Customer::create([
            'merchant_id' => $merchant->id,
            'phone' => $person['phone'],
            'name' => $person['name'],
            'registered_by_user_id' => $rep->getKey(),
            'registered_at_branch_id' => $rep->branch_id,
            'consent_status' => ConsentStatus::Granted,
            'consent_recorded_at' => now(),
            'current_cycle_number' => 1,
            'is_active' => true,
        ]);
    }

    /** @param list<array{amount: float, days_ago: int}> $sales */
    private function backdate(Customer $customer, array $sales): void
    {
        $latest = collect($sales)->min('days_ago');

        $customer->forceFill([
            'last_purchase_at' => now()->subDays($latest),
            'created_at' => now()->subDays(collect($sales)->max('days_ago')),
        ])->save();
    }

    private function daysAgo(int $days): string
    {
        return Carbon::now()->subDays($days)->toDateString();
    }

    private function userFor(Merchant $merchant, UserRole $role): ?User
    {
        return User::where('merchant_id', $merchant->id)->where('role', $role)->first();
    }
}
