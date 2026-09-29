<?php

namespace Database\Seeders;

use App\Enums\ConsentStatus;
use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvoiceService;
use App\Services\Loyalty\LoyaltyEngine;
use App\Services\Loyalty\RedemptionService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A showroom shop: one fictional chain, three branches, and a year of trade.
 *
 * Built to be shown to somebody deciding whether to buy. A prospect opening an
 * empty system sees a form; opening this one sees what nine months of their own
 * counter would have produced — which customers came back, what the discounts cost,
 * which branch outsells which.
 *
 * Every sale goes through InvoiceService and every reward through RedemptionService,
 * the paths the application itself uses. Rows written by hand would produce a ledger
 * the screens cannot explain: the balances shown are derived from these entries, so
 * an entry the engine would not have written is a number with no account behind it.
 *
 * Isolated to its own merchant, matched by commercial register. It creates or
 * refreshes that one shop and touches nothing else in the database — a re-run
 * rebuilds the showroom and leaves every real merchant alone.
 *
 *   php artisan db:seed --class=DemoShowcaseSeeder
 */
class DemoShowcaseSeeder extends Seeder
{
    /* ---------------------------------------------------------------------
     | Everything worth arguing about is here. The money figures are the only
     | guesses in this file: they should look ordinary to a shopkeeper in the
     | country the demo is shown in, so change them before changing anything else.
     --------------------------------------------------------------------- */

    private const REGISTER = 'DEMO-0001';

    private const SHOP = 'أسواق الياسمين';

    private const CURRENCY = 'SYP';

    /*
     * Sized so that the shop's best customers spend about four million a year —
     * roughly a hundred thousand a visit, thirty-odd visits. Every other figure
     * below follows from that one, so change it first and re-check the rest.
     */
    private const INVOICE_LOW = 30_000;

    private const INVOICE_HIGH = 165_000;

    /**
     * What a customer must accumulate in one cycle before a reward is due.
     *
     * At eight hundred thousand a regular earns once or twice a year and the most
     * loyal four or five times, which is what makes the rewards column on the demo
     * look like a programme rather than a rounding error.
     */
    private const THRESHOLD = 1_000_000;

    /**
     * Five per cent, not ten.
     *
     * A reward is a percentage of everything the customer spent in the cycle, so
     * ten per cent hands back a tenth of the takings from your best customers — and
     * the shop owner being shown this demo works that out immediately. Five sits
     * inside the eight per cent ceiling the objectives set for programme cost while
     * still being worth a customer's while, which is the only place a loyalty
     * scheme can live.
     */
    private const REWARD_PERCENT = 5;

    /** Rarely reached, by design: it should be visible as a protection, not felt
        as a penalty on every single reward. */
    private const MAX_DISCOUNT = 60_000;

    /** Invoices below this are recorded but do not count (BR-003). */
    private const MIN_INVOICE = 20_000;

    private const CUSTOMERS = 48;

    /** The demo password, shown to whoever is being given the tour. The same one
        the development seeds use, so there is a single password to remember. */
    private const PASSWORD = 'password';

    private const EMAIL = 'demo@yasmin.test';

    private InvoiceService $invoices;

    private RedemptionService $redemptions;

    public function run(): void
    {
        $this->invoices = new InvoiceService(app(LoyaltyEngine::class), app(AuditLogger::class));
        $this->redemptions = app(RedemptionService::class);

        $merchant = $this->merchant();

        $this->wipeTrade($merchant);

        $branches = $this->branches($merchant);
        $staff = $this->staff($merchant, $branches);

        /*
         * Everything that writes trade runs pinned to this merchant.
         *
         * merchant_id is not passed by hand anywhere in the application — it is
         * stamped from the tenant the request resolved, and the same scope keeps one
         * shop's rows out of another's. A seeder has no request behind it, so
         * without this the column arrives null and the insert is rejected. Pinning
         * here is also what makes the wipe and the reads below see this shop only.
         */
        app(TenantContext::class)->for($merchant->id, function () use ($merchant, $branches, $staff): void {
            $rule = $this->rule($merchant);

            $this->trade($merchant, $branches, $staff, $rule);
        });

        $this->report($merchant);
    }

    /* =====================================================================
     | The shop
     ===================================================================== */

    private function merchant(): Merchant
    {
        $plan = SubscriptionPlan::orderByDesc('monthly_price')->first()
            ?? SubscriptionPlan::create([
                'code' => 'unlimited',
                'name' => 'Unlimited',
                'max_branches' => null,
                'max_users' => null,
                'max_monthly_invoices' => null,
                'monthly_price' => 120,
            ]);

        return Merchant::updateOrCreate(
            ['commercial_register' => self::REGISTER],
            [
                'name' => self::SHOP,
                'trade_name' => self::SHOP,
                'owner_name' => 'سامي الياسمين',
                'email' => self::EMAIL,
                'phone' => '0911234567',
                'city' => 'دمشق',
                'currency' => self::CURRENCY,
                'status' => MerchantStatus::Active,
                'status_changed_at' => now(),
                'activated_at' => Carbon::create(now()->year, 1, 1),
                'email_verified_at' => now(),
                'submitted_at' => Carbon::create(now()->year, 1, 1),
                'reviewed_at' => Carbon::create(now()->year, 1, 1),
                'subscription_plan_id' => $plan->id,
                'subscription_ends_at' => now()->addYear()->toDateString(),
            ],
        );
    }

    /** @return array<int, Branch> */
    private function branches(Merchant $merchant): array
    {
        $defined = [
            ['name' => 'فرع المزة', 'city' => 'دمشق', 'weight' => 5],
            ['name' => 'فرع الفرقان', 'city' => 'حلب', 'weight' => 3],
            ['name' => 'فرع الأزهري', 'city' => 'اللاذقية', 'weight' => 2],
        ];

        $branches = [];

        foreach ($defined as $one) {
            $branch = Branch::updateOrCreate(
                ['merchant_id' => $merchant->id, 'name' => $one['name']],
                ['city' => $one['city'], 'is_active' => true],
            );

            // Carried on the model only for the weighting below; not a column.
            $branch->weight = $one['weight'];

            $branches[] = $branch;
        }

        return $branches;
    }

    /**
     * An owner, a manager for each branch, and two representatives under each —
     * the shape the request asked for.
     *
     * @param  array<int, Branch>  $branches
     * @return array{owner: User, managers: array<int, User>, reps: array<int, array<int, User>>}
     */
    private function staff(Merchant $merchant, array $branches): array
    {
        $owner = $this->user($merchant, null, self::EMAIL, 'سامي الياسمين', UserRole::MerchantOwner);

        $names = [
            ['مدير' => 'رامي خوري',   'reps' => ['طارق صالح', 'هناء درويش']],
            ['مدير' => 'وسيم العطار', 'reps' => ['ليلى منصور', 'أنس حلاق']],
            ['مدير' => 'زياد نصار',   'reps' => ['ديما بركات', 'فادي عيسى']],
        ];

        $managers = [];
        $reps = [];

        foreach ($branches as $i => $branch) {
            $slug = 'b'.($i + 1);

            $managers[$i] = $this->user(
                $merchant, $branch->id, "manager.{$slug}@yasmin.test",
                $names[$i]['مدير'], UserRole::BranchManager,
            );

            $reps[$i] = [];

            foreach ($names[$i]['reps'] as $n => $repName) {
                $reps[$i][] = $this->user(
                    $merchant, $branch->id, "rep{$n}.{$slug}@yasmin.test",
                    $repName, UserRole::SalesRep,
                );
            }
        }

        return ['owner' => $owner, 'managers' => $managers, 'reps' => $reps];
    }

    private function user(Merchant $m, ?int $branchId, string $email, string $name, UserRole $role): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'merchant_id' => $m->id,
                'branch_id' => $branchId,
                'name' => $name,
                'phone' => '09'.random_int(10000000, 99999999),
                'password' => self::PASSWORD,
                'role' => $role,
                'status' => UserStatus::Active,
            ],
        );
    }

    private function rule(Merchant $merchant): LoyaltyRule
    {
        LoyaltyRule::where('merchant_id', $merchant->id)->delete();

        /*
         * array_merge, not the + operator.
         *
         * On arrays, + keeps the left-hand value for any key present on both sides,
         * so `defaults() + [...]` silently discards every figure set here and the
         * showroom runs on a threshold of 1,000 with a 50 ceiling. It shows up as
         * rewards worth 50 paid out in the first fortnight and nothing after.
         */
        return LoyaltyRule::create(array_merge(LoyaltyRule::defaults(), [
            'merchant_id' => $merchant->id,
            'version' => 1,
            'threshold_amount' => self::THRESHOLD,
            'reward_value' => self::REWARD_PERCENT,
            'max_discount_amount' => self::MAX_DISCOUNT,
            'min_invoice_amount' => self::MIN_INVOICE,
            'effective_from' => Carbon::create(now()->year, 1, 1)->toDateString(),
            'is_active' => true,
        ]));
    }

    /* =====================================================================
     | The trade
     ===================================================================== */

    /**
     * @param  array<int, Branch>  $branches
     * @param  array{owner: User, managers: array<int, User>, reps: array<int, array<int, User>>}  $staff
     */
    private function trade(Merchant $merchant, array $branches, array $staff, LoyaltyRule $rule): void
    {
        $start = Carbon::create(now()->year, 1, 1);
        $today = now()->startOfDay();
        $days = max(1, $start->diffInDays($today));

        $number = 1;

        foreach ($this->people() as $person) {
            $home = $this->pickBranch($branches);
            $homeIndex = array_search($home, $branches, true);

            $customer = Customer::create([
                'merchant_id' => $merchant->id,
                'phone' => $person['phone'],
                'name' => $person['name'],
                'registered_by_user_id' => $staff['reps'][$homeIndex][0]->id,
                'registered_at_branch_id' => $home->id,
                'consent_status' => ConsentStatus::Granted,
                'consent_recorded_at' => $start,
                'current_cycle_number' => 1,
                'is_active' => true,
            ]);

            $dates = $this->visitDates($person['visits'], $start, $days, $person['lapsed']);

            foreach ($dates as $date) {
                // Most visits at the home branch; the rest anywhere, which is what
                // makes the cross-branch accrual visible in the reports.
                $branch = random_int(1, 10) <= 8 ? $home : $this->pickBranch($branches);
                $index = array_search($branch, $branches, true);

                /*
                 * Usually a representative, now and then the manager or the owner.
                 *
                 * Not only for variety in the staff-performance report. The till
                 * screen carries two cards side by side — what the shop took today,
                 * and what the signed-in user entered today — and with every sale
                 * recorded by a rep, an owner giving the tour reads "5 invoices"
                 * beside "nothing recorded yet" and takes it for a fault. Owners do
                 * serve customers; letting this one do so keeps both cards true.
                 */
                $roll = random_int(1, 100);

                $rep = match (true) {
                    $roll <= 6 => $staff['owner'],
                    $roll <= 18 => $staff['managers'][$index],
                    default => $staff['reps'][$index][random_int(0, 1)],
                };

                $this->invoices->record([
                    'customer_id' => $customer->id,
                    'branch_id' => $branch->id,
                    'invoice_number' => 'INV-'.now()->year.'-'.str_pad((string) $number++, 5, '0', STR_PAD_LEFT),
                    'amount' => $this->amount($person['basket']),
                    'invoice_date' => $date->toDateString(),
                ], $rep);

                $this->backdate($customer, $date);

                // A reward is paid when it falls due, by the manager of the branch
                // the customer happens to be standing in — not all at once at the end.
                if ($this->dueNow($customer, $rule)) {
                    $this->payReward($customer, $staff['managers'][$index], $date);
                }
            }

            $customer->forceFill([
                'created_at' => $dates[0] ?? $start,
                'last_purchase_at' => end($dates) ?: $start,
            ])->save();
        }

        $this->ensureOwnerServedToday($staff['owner']);
    }

    /**
     * Guarantees the owner has something under "my entries today".
     *
     * Six sales in a hundred over nine months does not promise one this morning, and
     * the demo is opened on whatever morning it is opened. Two of today's invoices
     * are moved onto the owner, ledger entry and all, so the card beside them is
     * never empty while the shop's own total sits at five.
     */
    private function ensureOwnerServedToday(User $owner): void
    {
        $today = Invoice::whereDate('invoice_date', now()->toDateString());

        if ((clone $today)->where('user_id', $owner->id)->exists()) {
            return;
        }

        $ids = (clone $today)->latest('id')->limit(2)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        Invoice::whereIn('id', $ids)->update(['user_id' => $owner->id]);

        LedgerEntry::where('source_type', Invoice::class)
            ->whereIn('source_id', $ids)
            ->update(['created_by' => $owner->id]);
    }

    private function dueNow(Customer $customer, LoyaltyRule $rule): bool
    {
        $snapshot = app(LoyaltyEngine::class)->snapshot($customer->fresh(), $rule);

        // Four in five are collected. The rest are customers who have earned a
        // reward and not come back for it — a real shop always has some.
        return $snapshot !== null && $snapshot->isEligible() && random_int(1, 5) > 1;
    }

    private function payReward(Customer $customer, User $manager, Carbon $on): void
    {
        try {
            $redemption = $this->redemptions->redeem($customer->fresh(), $manager);
        } catch (\Throwable) {
            return;
        }

        /*
         * Stamped with the day it happened rather than the moment it was generated.
         * Without this every reward in nine months of history carries today's date,
         * and the first report a prospect opens contradicts the story it is telling.
         */
        $when = $on->copy()->setTime(random_int(10, 20), random_int(0, 59));

        /*
         * redeemed_at as well as created_at, and it is redeemed_at that matters.
         *
         * The dashboard and the reports date a reward by when it was handed over,
         * not by when the row appeared. Moving only created_at left every reward in
         * the year stamped as paid today: the "today" tile showed twenty-nine
         * rewards and the month's programme cost came out at fifteen per cent —
         * the one figure the whole demo exists to keep low.
         */
        Redemption::where('id', $redemption->id)->update([
            'redeemed_at' => $when,
            'created_at' => $when,
            'updated_at' => $when,
        ]);

        LedgerEntry::where('source_type', Redemption::class)
            ->where('source_id', $redemption->id)
            ->update(['created_at' => $when, 'updated_at' => $when]);
    }

    /** Moves the invoice and its ledger entry off today and onto the day of the sale. */
    private function backdate(Customer $customer, Carbon $date): void
    {
        $when = $date->copy()->setTime(random_int(9, 21), random_int(0, 59));

        $invoice = Invoice::where('customer_id', $customer->id)->latest('id')->first();

        if ($invoice === null) {
            return;
        }

        Invoice::where('id', $invoice->id)->update(['created_at' => $when, 'updated_at' => $when]);

        LedgerEntry::where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->update(['created_at' => $when, 'updated_at' => $when]);
    }

    /**
     * Visit dates spread over the year, thinned out at the start and busier near
     * the end, the way a shop that is growing actually looks.
     *
     * @return array<int, Carbon>
     */
    private function visitDates(int $visits, Carbon $start, int $days, bool $lapsed): array
    {
        // Somebody who stopped coming did so months ago; their history stops there.
        $ceiling = max(1, $lapsed ? (int) ($days * 0.45) : $days);

        $offsets = [];

        for ($i = 0; $i < $visits; $i++) {
            $roll = random_int(0, $ceiling);

            /*
             * Pulled towards the recent end for everyone still shopping.
             *
             * A flat spread produces a shop whose sales fall month after month —
             * partly because the lapsed customers only trade in the first half, and
             * partly because the current month is never finished. That is the one
             * shape a prospect must not be shown, since the chart is the argument.
             * Taking the larger of two rolls bends the same range upwards without
             * inventing a trend the data cannot carry.
             */
            $offsets[] = $lapsed ? $roll : max($roll, random_int(0, $ceiling));
        }

        sort($offsets);

        return array_map(fn (int $o): Carbon => $start->copy()->addDays($o), $offsets);
    }

    /**
     * Invoice totals land on a round figure.
     *
     * A till in a shop does produce 96,347 — but a demo full of them reads as
     * generated noise, and the eye spends its attention on the digits instead of on
     * the report. Rounding to the nearest five thousand keeps the spread while
     * making every figure legible at a glance.
     */
    private function amount(float $basket): float
    {
        $step = 5_000;

        // One in fourteen falls under the rule's floor: recorded, and visibly not
        // counted, which is BR-003 on screen instead of in a document.
        if (random_int(1, 14) === 1) {
            return (float) (random_int(1, (int) (self::MIN_INVOICE / 1_000) - 1) * 1_000);
        }

        $low = (int) ceil(self::INVOICE_LOW * $basket / $step);
        $high = (int) floor(self::INVOICE_HIGH * $basket / $step);

        return (float) (random_int($low, max($low, $high)) * $step);
    }

    /** @param array<int, Branch> $branches */
    private function pickBranch(array $branches): Branch
    {
        $pool = [];

        foreach ($branches as $branch) {
            for ($i = 0; $i < ($branch->weight ?? 1); $i++) {
                $pool[] = $branch;
            }
        }

        return $pool[array_rand($pool)];
    }

    /* =====================================================================
     | The customers
     ===================================================================== */

    /**
     * Forty-eight customers in four shapes: the regulars who carry the shop, the
     * steady middle, the occasional visitor, and the ones who stopped coming. A
     * report built on customers who all behave the same proves nothing.
     *
     * @return array<int, array{name: string, phone: string, visits: int, basket: float, lapsed: bool}>
     */
    private function people(): array
    {
        $first = [
            'سامر', 'ريم', 'مازن', 'لينا', 'فادي', 'هالة', 'عمر', 'نور',
            'رنا', 'خالد', 'سلمى', 'باسل', 'دانا', 'أيهم', 'لمى', 'غيث',
            'جنى', 'حسام', 'ميساء', 'طارق', 'رهف', 'وائل', 'سارة', 'كنان',
        ];

        $last = [
            'الحلبي', 'العبد الله', 'قاسم', 'حدّاد', 'مرعي', 'الخوري', 'الشامي',
            'صالح', 'دياب', 'العلي', 'شعبان', 'نجّار', 'عيسى', 'برهان', 'الأسمر',
            'سلّوم',
        ];

        $shapes = [
            ['visits' => [26, 40], 'basket' => 1.25, 'lapsed' => false, 'share' => 8],   // الأوفياء
            ['visits' => [12, 22], 'basket' => 1.00, 'lapsed' => false, 'share' => 18],  // المنتظمون
            ['visits' => [4, 10],  'basket' => 0.85, 'lapsed' => false, 'share' => 14],  // العابرون
            ['visits' => [6, 14],  'basket' => 1.05, 'lapsed' => true,  'share' => 8],   // المنقطعون
        ];

        /*
         * Every pairing used once.
         *
         * Cycling the two lists by index produced neighbours who shared a surname —
         * "هالة العبد الله" beside "لينا العبد الله" — which reads as generated data
         * rather than as a customer list. Building all the pairs and shuffling them
         * spends each combination once and lets any repeated surname fall where it
         * would in a real town.
         */
        $pairs = [];

        foreach ($first as $f) {
            foreach ($last as $l) {
                $pairs[] = $f.' '.$l;
            }
        }

        shuffle($pairs);

        $people = [];
        $n = 0;

        foreach ($shapes as $shape) {
            for ($i = 0; $i < $shape['share'] && $n < self::CUSTOMERS; $i++, $n++) {
                $people[] = [
                    'name' => $pairs[$n],
                    'phone' => '09'.str_pad((string) (31000000 + $n * 137), 8, '0', STR_PAD_LEFT),
                    'visits' => random_int($shape['visits'][0], $shape['visits'][1]),
                    'basket' => $shape['basket'],
                    'lapsed' => $shape['lapsed'],
                ];
            }
        }

        return $people;
    }

    /* =====================================================================
     | Housekeeping
     ===================================================================== */

    /**
     * Clears this shop's trade so the seeder can be run again without stacking a
     * second year on top of the first. Scoped to its own customers throughout — a
     * blanket delete would take the real merchants with it.
     */
    private function wipeTrade(Merchant $merchant): void
    {
        $ids = Customer::withoutGlobalScopes()->where('merchant_id', $merchant->id)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($ids): void {
            Redemption::withoutGlobalScopes()->whereIn('customer_id', $ids)->forceDelete();
            LedgerEntry::withoutGlobalScopes()->whereIn('customer_id', $ids)->forceDelete();
            Invoice::withoutGlobalScopes()->whereIn('customer_id', $ids)->forceDelete();
            Customer::withoutGlobalScopes()->whereIn('id', $ids)->forceDelete();
        });
    }

    private function report(Merchant $merchant): void
    {
        $customers = Customer::withoutGlobalScopes()->where('merchant_id', $merchant->id)->count();
        $invoices = Invoice::withoutGlobalScopes()->where('merchant_id', $merchant->id)->count();
        $rewards = Redemption::withoutGlobalScopes()->where('merchant_id', $merchant->id)->count();
        $sales = Invoice::withoutGlobalScopes()->where('merchant_id', $merchant->id)->sum('amount');

        $this->command->info('');
        $this->command->info('  '.self::SHOP.' — جاهز');
        $this->command->info('  ٣ فروع · '.$customers.' زبون · '.$invoices.' فاتورة · '.$rewards.' مكافأة مصروفة');
        $discounts = (float) Redemption::withoutGlobalScopes()
            ->where('merchant_id', $merchant->id)->sum('discount_amount');

        $share = $sales > 0 ? $discounts / $sales * 100 : 0;

        $this->command->info('  إجمالي المبيعات: '.number_format((float) $sales).' '.self::CURRENCY);
        $this->command->info('  كلفة الحسومات : '.number_format($discounts).' '.self::CURRENCY
            .'  ('.number_format($share, 1).'% من المبيعات)');
        $this->command->info('');
        $this->command->info('  للدخول:  '.self::EMAIL.'  /  '.self::PASSWORD);
        $this->command->info('');
    }
}
