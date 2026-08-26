<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\RewardType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The anti-fraud signals of BRD 12.
 *
 * Half of these cases are about what must NOT fire. A screen that lights up during
 * ordinary trading teaches its reader to ignore it, and an ignored control is worse
 * than no control — so every detector is tested against the innocent version of its
 * own pattern as well as the guilty one.
 */
class FraudSignalTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private User $owner;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();

        $this->owner = User::factory()->owner($this->merchant->id)->create(['name' => 'Owner']);
        $this->manager = User::factory()->branchManager($this->branch)->create(['name' => 'Manager']);
        $this->rep = User::factory()->salesRep($this->branch)->create(['name' => 'Night Rep']);

        LoyaltyRule::withoutGlobalScopes()->create([
            ...LoyaltyRule::defaults(),
            'merchant_id' => $this->merchant->id,
            'effective_from' => now()->subYear()->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return app(TenantContext::class)->for($this->merchant->id, fn () => Customer::create([
            'phone' => '099' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'name' => 'Customer',
            'registered_at_branch_id' => $this->branch->id,
            ...$attributes,
        ]));
    }

    /**
     * Written directly so the entry timestamp can be placed deliberately — which is
     * the whole subject of several of these detectors.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function invoice(array $attributes = [], ?\Carbon\CarbonInterface $enteredAt = null): Invoice
    {
        static $sequence = 0;
        $sequence++;

        $invoice = Invoice::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->rep->id,
            'invoice_number' => 'INV-' . $sequence,
            'amount' => 100,
            'invoice_date' => now()->toDateString(),
            ...$attributes,
        ]);

        if ($enteredAt !== null) {
            $invoice->forceFill(['created_at' => $enteredAt])->saveQuietly();
        }

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function signals(User $user, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/signals?' . http_build_query($query));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detect(array $query = []): array
    {
        return $this->signals($this->owner, $query)->assertOk()->json('data');
    }

    private function ofType(string $type, array $query = []): array
    {
        return array_values(array_filter($this->detect($query), fn ($s) => $s['type'] === $type));
    }

    // -----------------------------------------------------------------
    // Who may read it
    // -----------------------------------------------------------------

    public function test_only_the_owner_reads_the_signals(): void
    {
        // A branch manager is among the people this screen examines, so they are not
        // allowed to read it — unlike the ordinary reports, which they can.
        $this->signals($this->manager)->assertForbidden();
        $this->signals($this->rep)->assertForbidden();
        $this->signals($this->owner)->assertOk();
    }

    public function test_a_quiet_period_produces_nothing_at_all(): void
    {
        $this->invoice();
        $this->invoice();

        // Ordinary trading, ordinary hours, nothing to say. An empty screen is the
        // correct answer far more often than not.
        $this->assertSame([], $this->detect());
    }

    // -----------------------------------------------------------------
    // AF-10 — entries when the shop is shut
    // -----------------------------------------------------------------

    public function test_entries_made_in_the_middle_of_the_night_are_reported(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->invoice([], now()->setTime(3, 20));
        }

        $signals = $this->ofType('out_of_hours');

        $this->assertCount(1, $signals);
        $this->assertSame('AF-10', $signals[0]['code']);
        $this->assertSame('Night Rep', $signals[0]['subject']);
        $this->assertSame(3, $signals[0]['count']);
    }

    public function test_a_single_late_entry_is_not_a_pattern(): void
    {
        $this->invoice([], now()->setTime(2, 0));
        $this->invoice([], now()->setTime(13, 0));

        // One late night is a late night. The threshold exists so that saying so
        // costs nothing.
        $this->assertSame([], $this->ofType('out_of_hours'));
    }

    public function test_business_hours_entries_are_never_flagged(): void
    {
        foreach ([8, 12, 17, 22] as $hour) {
            $this->invoice([], now()->setTime($hour, 30));
        }

        $this->assertSame([], $this->ofType('out_of_hours'));
    }

    // -----------------------------------------------------------------
    // AF-02 — entries that keep needing to be undone
    // -----------------------------------------------------------------

    public function test_a_rep_whose_entries_keep_being_cancelled_is_reported(): void
    {
        // Four entries, three of them cancelled: three quarters of their work.
        $invoices = [$this->invoice(), $this->invoice(), $this->invoice(), $this->invoice()];

        foreach (array_slice($invoices, 0, 3) as $invoice) {
            $this->actingAs($this->rep, 'sanctum')
                ->postJson("/api/v1/invoices/{$invoice->id}/corrections", [
                    'type' => 'cancel',
                    'reason' => 'Entered against the wrong customer.',
                ])->assertCreated();
        }

        $signals = $this->ofType('frequent_corrections');

        $this->assertCount(1, $signals);
        $this->assertSame('AF-02', $signals[0]['code']);
        $this->assertSame('high', $signals[0]['severity']);
        $this->assertSame(3, $signals[0]['count']);
        $this->assertSame(4, $signals[0]['detail']['entries']);
    }

    public function test_the_busiest_rep_is_not_flagged_merely_for_being_busy(): void
    {
        // Three corrections out of a hundred entries is a rate, not a pattern.
        // Flagging the busiest person for being busy is how a control loses its
        // credibility.
        $invoices = [];

        for ($i = 0; $i < 100; $i++) {
            $invoices[] = $this->invoice();
        }

        foreach (array_slice($invoices, 0, 3) as $invoice) {
            $this->actingAs($this->rep, 'sanctum')
                ->postJson("/api/v1/invoices/{$invoice->id}/corrections", [
                    'type' => 'cancel',
                    'reason' => 'An ordinary mistake, of which there are a few.',
                ])->assertCreated();
        }

        $this->assertSame([], $this->ofType('frequent_corrections'));
    }

    // -----------------------------------------------------------------
    // Back-dated entries
    // -----------------------------------------------------------------

    public function test_sales_keyed_in_long_after_they_happened_are_reported(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->invoice(['invoice_date' => now()->subDays(20)->toDateString()]);
        }

        $signals = $this->ofType('backdated');

        $this->assertCount(1, $signals);
        $this->assertSame(3, $signals[0]['count']);
        $this->assertGreaterThanOrEqual(20, $signals[0]['detail']['worst_days']);
    }

    public function test_a_sale_entered_the_next_morning_is_not_backdating(): void
    {
        foreach ([1, 2, 3] as $daysAgo) {
            $this->invoice(['invoice_date' => now()->subDays($daysAgo)->toDateString()]);
        }

        // The till was busy; somebody caught up. That is not the pattern.
        $this->assertSame([], $this->ofType('backdated'));
    }

    // -----------------------------------------------------------------
    // AF-05 — the same card collecting reward after reward
    // -----------------------------------------------------------------

    public function test_a_customer_collecting_reward_after_reward_is_reported(): void
    {
        $customer = $this->customer(['name' => 'Frequent Winner']);

        for ($i = 1; $i <= 3; $i++) {
            $this->redemption($customer, cycle: $i);
        }

        $signals = $this->ofType('repeated_redemptions');

        $this->assertCount(1, $signals);
        $this->assertSame('AF-05', $signals[0]['code']);
        $this->assertSame('Frequent Winner', $signals[0]['subject']);
        $this->assertSame(3, $signals[0]['count']);
        $this->assertSame('medium', $signals[0]['severity']);
    }

    public function test_an_exception_among_them_raises_the_severity(): void
    {
        $customer = $this->customer();

        $this->redemption($customer, cycle: 1);
        $this->redemption($customer, cycle: 2);
        // BR-014: somebody authorised this one by hand.
        $this->redemption($customer, cycle: 3, override: true);

        $signals = $this->ofType('repeated_redemptions');

        $this->assertSame('high', $signals[0]['severity']);
        $this->assertSame(1, $signals[0]['detail']['overrides']);
    }

    public function test_two_rewards_in_a_period_is_a_loyal_customer(): void
    {
        $customer = $this->customer();

        $this->redemption($customer, cycle: 1);
        $this->redemption($customer, cycle: 2);

        // Which is the point of the programme, not a problem with it.
        $this->assertSame([], $this->ofType('repeated_redemptions'));
    }

    // -----------------------------------------------------------------
    // AF-03 and AF-11 — a rep and a customer, alone together
    // -----------------------------------------------------------------

    public function test_a_rep_who_registered_a_customer_and_serves_only_them_is_reported(): void
    {
        $customer = $this->customer([
            'name' => 'Their Own Customer',
            'registered_by_user_id' => $this->rep->id,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->invoice(['customer_id' => $customer->id, 'user_id' => $this->rep->id]);
        }

        $signals = $this->ofType('rep_customer_concentration');

        $this->assertCount(1, $signals);
        $this->assertSame('AF-03', $signals[0]['code']);
        $this->assertSame('high', $signals[0]['severity']);
        $this->assertSame('Their Own Customer', $signals[0]['subject']);
        $this->assertSame('Night Rep', $signals[0]['detail']['user']);
        // JSON hands a whole number back as an int, so the share is compared loosely.
        $this->assertEquals(100, $signals[0]['detail']['share']);
    }

    public function test_a_regular_with_a_favourite_cashier_is_not_enough(): void
    {
        $second = User::factory()->salesRep($this->branch)->create(['name' => 'Second Rep']);

        $customer = $this->customer(['registered_by_user_id' => $this->rep->id]);

        // Mostly served by the rep who registered them, but not exclusively — a
        // regular does have a favourite cashier.
        for ($i = 0; $i < 4; $i++) {
            $this->invoice(['customer_id' => $customer->id, 'user_id' => $this->rep->id]);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->invoice(['customer_id' => $customer->id, 'user_id' => $second->id]);
        }

        $this->assertSame([], $this->ofType('rep_customer_concentration'));
    }

    public function test_a_customer_registered_by_someone_else_is_not_the_pattern(): void
    {
        // Every purchase entered by one rep, but a different person registered the
        // card. It takes both halves to look like a fabricated customer.
        $customer = $this->customer(['registered_by_user_id' => $this->manager->id]);

        for ($i = 0; $i < 6; $i++) {
            $this->invoice(['customer_id' => $customer->id, 'user_id' => $this->rep->id]);
        }

        $this->assertSame([], $this->ofType('rep_customer_concentration'));
    }

    // -----------------------------------------------------------------
    // Scope
    // -----------------------------------------------------------------

    public function test_cancelled_invoices_do_not_feed_the_detectors(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->invoice(
                ['status' => InvoiceStatus::Cancelled, 'cancelled_at' => now()],
                now()->setTime(3, 0)
            );
        }

        // The entry was undone; counting it twice — once as a cancellation, once as
        // a night entry — would double-report the same act.
        $this->assertSame([], $this->ofType('out_of_hours'));
    }

    public function test_the_signals_stay_inside_the_window(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->invoice([], now()->subMonths(3)->setTime(3, 0));
        }

        $this->assertSame([], $this->ofType('out_of_hours'));

        $found = $this->ofType('out_of_hours', [
            'from' => now()->subMonths(4)->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertCount(1, $found);
    }

    public function test_no_signal_ever_mentions_another_stores_staff(): void
    {
        $other = Merchant::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $theirRep = User::factory()->salesRep($otherBranch)->create(['name' => 'Their Rep']);

        for ($i = 0; $i < 4; $i++) {
            $invoice = Invoice::withoutGlobalScopes()->create([
                'merchant_id' => $other->id,
                'branch_id' => $otherBranch->id,
                'user_id' => $theirRep->id,
                'invoice_number' => 'THEIR-' . $i,
                'amount' => 100,
                'invoice_date' => now()->toDateString(),
            ]);

            $invoice->forceFill(['created_at' => now()->setTime(3, 0)])->saveQuietly();
        }

        $this->assertSame([], $this->detect());
    }

    // -----------------------------------------------------------------

    private function redemption(Customer $customer, int $cycle, bool $override = false): void
    {
        Redemption::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'loyalty_rule_id' => LoyaltyRule::withoutGlobalScopes()->first()->id,
            'cycle_number' => $cycle,
            'cycle_total_amount' => 1000,
            'cycle_invoice_count' => 2,
            'reward_type' => RewardType::Percentage,
            'computed_amount' => 100,
            'discount_amount' => 50,
            'carried_over_amount' => 0,
            'performed_by' => $this->manager->id,
            'is_override' => $override,
            'override_reason' => $override ? 'Authorised by the owner.' : null,
            'override_approved_by' => $override ? $this->owner->id : null,
            'redeemed_at' => now()->subDays($cycle),
        ]);
    }
}
