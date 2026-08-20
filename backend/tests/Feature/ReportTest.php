<?php

namespace Tests\Feature;

use App\Enums\RewardType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reports of BRD 9 (RPT-01 to RPT-05).
 *
 * A report that quietly counts the wrong rows is worse than a broken one: nobody
 * finds out, and decisions get made on it. So the cases here check the boundaries
 * that are easy to get wrong — a cancelled sale, an invoice dated outside the
 * window, another branch, another merchant — rather than only the happy total.
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $damascus;

    private Branch $aleppo;

    private User $owner;

    private User $manager;

    private User $rep;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->damascus = Branch::factory()->for($this->merchant)->create(['name' => 'Damascus']);
        $this->aleppo = Branch::factory()->for($this->merchant)->create(['name' => 'Aleppo']);

        $this->owner = User::factory()->owner($this->merchant->id)->create(['name' => 'Owner']);
        $this->manager = User::factory()->branchManager($this->damascus)->create(['name' => 'Manager']);
        $this->rep = User::factory()->salesRep($this->damascus)->create(['name' => 'Rep']);

        app(TenantContext::class)->set($this->merchant->id);

        $this->customer = Customer::create([
            'phone' => '0991234567',
            'name' => 'Sami',
            'registered_at_branch_id' => $this->damascus->id,
        ]);

        app(TenantContext::class)->forget();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishRule(array $overrides = []): LoyaltyRule
    {
        return LoyaltyRule::withoutGlobalScopes()->create([
            ...LoyaltyRule::defaults(),
            'merchant_id' => $this->merchant->id,
            'effective_from' => now()->subYear()->toDateString(),
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * Written directly so a sale can be dated and attributed freely, which is what
     * the boundary cases need. The flow that creates these for real is covered in
     * SalesFlowTest.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sale(array $attributes = []): Invoice
    {
        static $sequence = 0;
        $sequence++;

        return Invoice::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'branch_id' => $this->damascus->id,
            'user_id' => $this->rep->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-' . $sequence,
            'amount' => 100,
            'invoice_date' => now()->toDateString(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function report(string $name, User $user, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/' . $name . '?' . http_build_query($query));
    }

    // -----------------------------------------------------------------
    // RPT-01 — the summary
    // -----------------------------------------------------------------

    public function test_the_summary_counts_sales_in_the_window_and_nothing_else(): void
    {
        $this->sale(['amount' => 300]);
        $this->sale(['amount' => 200]);

        // Cancelled: not a sale, and counting it would make the discount cost look
        // cheaper than it is (BR-010).
        $this->sale(['amount' => 1000, 'status' => 'cancelled', 'cancelled_at' => now()]);

        // Dated before the window opens.
        $this->sale(['amount' => 900, 'invoice_date' => now()->subMonths(3)->toDateString()]);

        $this->report('summary', $this->owner, [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.sales_total', '500.00')
            ->assertJsonPath('data.invoice_count', 2)
            ->assertJsonPath('data.average_invoice', '250.00')
            ->assertJsonPath('data.customers_served', 1)
            ->assertJsonPath('data.new_customers', 1);
    }

    public function test_the_summary_reports_the_discount_cost_as_a_share_of_sales(): void
    {
        $this->publishRule();
        $this->sale(['amount' => 1000]);

        // A reward of 50 against 1,000 of sales is 5%.
        $this->seedRedemption(discount: 50);

        $this->report('summary', $this->owner)
            ->assertOk()
            ->assertJsonPath('data.redemption_count', 1)
            ->assertJsonPath('data.discount_total', '50.00')
            ->assertJsonPath('data.discount_ratio', 5);
    }

    public function test_a_period_with_no_sales_answers_with_zeroes_rather_than_nulls(): void
    {
        $this->report('summary', $this->owner)
            ->assertOk()
            ->assertJsonPath('data.sales_total', '0.00')
            ->assertJsonPath('data.invoice_count', 0)
            ->assertJsonPath('data.average_invoice', '0.00')
            // Not a division by zero, and not "NaN" on the screen.
            ->assertJsonPath('data.discount_ratio', 0);
    }

    public function test_the_period_comes_back_with_the_figures(): void
    {
        $this->report('summary', $this->owner, [
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ])
            ->assertOk()
            ->assertJsonPath('period.from', '2026-01-01')
            ->assertJsonPath('period.to', '2026-01-31')
            ->assertJsonPath('period.days', 31)
            ->assertJsonPath('period.branch_locked', false);
    }

    public function test_a_backwards_or_oversized_range_is_refused(): void
    {
        $this->report('summary', $this->owner, ['from' => '2026-03-01', 'to' => '2026-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');

        $this->report('summary', $this->owner, ['from' => '2020-01-01', 'to' => '2026-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    // -----------------------------------------------------------------
    // Who may see what (BRD 7.2, FR-BRN-03)
    // -----------------------------------------------------------------

    public function test_a_sales_rep_has_no_reports_at_all(): void
    {
        foreach (['summary', 'customers', 'branches', 'rewards', 'staff'] as $report) {
            $this->report($report, $this->rep)->assertForbidden();
        }
    }

    public function test_a_branch_manager_asking_for_another_branch_still_gets_their_own(): void
    {
        $this->sale(['amount' => 300, 'branch_id' => $this->damascus->id]);
        $this->sale(['amount' => 700, 'branch_id' => $this->aleppo->id]);

        // Asking for Aleppo explicitly, which is not theirs.
        $this->report('summary', $this->manager, ['branch_id' => $this->aleppo->id])
            ->assertOk()
            ->assertJsonPath('period.branch_id', $this->damascus->id)
            ->assertJsonPath('period.branch_locked', true)
            ->assertJsonPath('data.sales_total', '300.00');
    }

    public function test_the_owner_sees_every_branch_and_can_narrow_to_one(): void
    {
        $this->sale(['amount' => 300, 'branch_id' => $this->damascus->id]);
        $this->sale(['amount' => 700, 'branch_id' => $this->aleppo->id]);

        $this->report('summary', $this->owner)
            ->assertOk()
            ->assertJsonPath('data.sales_total', '1000.00');

        $this->report('summary', $this->owner, ['branch_id' => $this->aleppo->id])
            ->assertOk()
            ->assertJsonPath('data.sales_total', '700.00');
    }

    // -----------------------------------------------------------------
    // RPT-02 — customers
    // -----------------------------------------------------------------

    public function test_the_customer_report_ranks_spenders_and_counts_the_ones_who_stopped(): void
    {
        app(TenantContext::class)->set($this->merchant->id);

        $quiet = Customer::create([
            'phone' => '0999999999',
            'name' => 'Quiet Customer',
            'last_purchase_at' => now()->subMonths(6),
        ]);

        app(TenantContext::class)->forget();

        $this->sale(['amount' => 400]);
        $this->sale(['amount' => 100, 'customer_id' => $quiet->id]);

        $response = $this->report('customers', $this->owner)->assertOk();

        $response->assertJsonPath('data.total_customers', 2)
            ->assertJsonPath('data.inactive', 1)
            ->assertJsonPath('data.top_customers.0.name', 'Sami')
            ->assertJsonPath('data.top_customers.0.total', '400.00')
            ->assertJsonPath('data.top_customers.1.total', '100.00');
    }

    public function test_a_manager_sees_customer_numbers_masked_and_the_owner_does_not(): void
    {
        $this->sale(['amount' => 400]);

        // BR-019: the customer base is the merchant's asset. A manager can
        // recognise a customer they know; they cannot build a list to take away.
        $this->report('customers', $this->manager)
            ->assertOk()
            ->assertJsonPath('data.top_customers.0.phone', '099****567');

        $this->report('customers', $this->owner)
            ->assertOk()
            ->assertJsonPath('data.top_customers.0.phone', '0991234567');
    }

    // -----------------------------------------------------------------
    // RPT-03 — branches
    // -----------------------------------------------------------------

    public function test_the_branch_report_lists_a_branch_that_sold_nothing(): void
    {
        $this->sale(['amount' => 500, 'branch_id' => $this->damascus->id]);

        $rows = collect($this->report('branches', $this->owner)->assertOk()->json('data'))
            ->keyBy('branch');

        $this->assertSame('500.00', $rows['Damascus']['sales_total']);

        // The quiet branch is the row worth seeing, so it is present, at zero.
        $this->assertSame('0.00', $rows['Aleppo']['sales_total']);
        $this->assertSame(0, $rows['Aleppo']['invoice_count']);
        $this->assertSame('0.00', $rows['Aleppo']['average_invoice']);
    }

    public function test_a_branch_manager_gets_one_row_in_the_branch_report(): void
    {
        $this->sale(['amount' => 500, 'branch_id' => $this->damascus->id]);
        $this->sale(['amount' => 500, 'branch_id' => $this->aleppo->id]);

        $this->report('branches', $this->manager)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.branch', 'Damascus');
    }

    // -----------------------------------------------------------------
    // RPT-04 — rewards
    // -----------------------------------------------------------------

    public function test_the_reward_report_separates_what_was_paid_from_what_was_calculated(): void
    {
        $this->publishRule();

        // The cap of BR-021 bit: 116 earned, 50 paid.
        $this->seedRedemption(discount: 50, computed: 116);

        $this->report('rewards', $this->owner)
            ->assertOk()
            ->assertJsonPath('data.by_type.0.reward_type', 'percentage')
            ->assertJsonPath('data.by_type.0.count', 1)
            ->assertJsonPath('data.by_type.0.paid', '50.00')
            ->assertJsonPath('data.by_type.0.computed', '116.00');
    }

    public function test_the_reward_report_counts_the_exceptions_separately(): void
    {
        $this->publishRule();
        $this->seedRedemption(discount: 50);
        $this->seedRedemption(discount: 40, override: true);

        $this->report('rewards', $this->owner)
            ->assertOk()
            // BR-014: exceptions are meant to be few, and countable.
            ->assertJsonPath('data.override_count', 1)
            ->assertJsonPath('data.override_total', '40.00');
    }

    public function test_the_reward_report_shows_the_credit_still_in_customers_pockets(): void
    {
        $this->publishRule(['reward_type' => RewardType::Voucher, 'reward_value' => 50]);

        $redemption = $this->seedRedemption(discount: 50, type: RewardType::Voucher);

        $this->seedVoucher($redemption, amount: 50);
        // Expired, so it is no longer owed to anyone.
        $this->seedVoucher($redemption, amount: 30, expiresAt: now()->subDay());
        // Already spent.
        $this->seedVoucher($redemption, amount: 20, usedAt: now());

        $this->report('rewards', $this->owner)
            ->assertOk()
            ->assertJsonPath('data.vouchers.issued_count', 3)
            ->assertJsonPath('data.vouchers.used_count', 1)
            ->assertJsonPath('data.vouchers.used_total', '20.00')
            // Only the one that is live and unspent.
            ->assertJsonPath('data.vouchers.outstanding_count', 1)
            ->assertJsonPath('data.vouchers.outstanding_total', '50.00');
    }

    // -----------------------------------------------------------------
    // RPT-05 — staff
    // -----------------------------------------------------------------

    public function test_the_staff_report_attributes_sales_to_whoever_entered_them(): void
    {
        $second = User::factory()->salesRep($this->damascus)->create(['name' => 'Second Rep']);

        $this->sale(['amount' => 300]);
        $this->sale(['amount' => 200]);
        $this->sale(['amount' => 900, 'user_id' => $second->id]);

        $rows = collect($this->report('staff', $this->owner)->assertOk()->json('data'))
            ->keyBy('name');

        // Ordered by sales, so the busiest is first.
        $this->assertSame('Second Rep', $this->report('staff', $this->owner)->json('data.0.name'));

        $this->assertSame('500.00', $rows['Rep']['sales_total']);
        $this->assertSame(2, $rows['Rep']['invoice_count']);
        $this->assertSame('250.00', $rows['Rep']['average_invoice']);
        $this->assertSame(1, $rows['Rep']['customers_served']);
    }

    public function test_the_staff_report_counts_correction_requests_including_refused_ones(): void
    {
        $this->publishRule();
        $invoice = $this->sale(['amount' => 500]);
        $second = $this->sale(['amount' => 300]);

        $rejected = $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/corrections", [
                'type' => 'cancel',
                'reason' => 'Entered against the wrong customer.',
            ])->assertCreated()->json('data.id');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/corrections/{$rejected}/reject")
            ->assertOk();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/v1/invoices/{$second->id}/corrections", [
                'type' => 'cancel',
                'reason' => 'The customer cancelled the order.',
            ])->assertCreated();

        $rows = collect($this->report('staff', $this->owner)->assertOk()->json('data'))
            ->keyBy('name');

        // A refused request is still a signal about who raised it, which is what
        // makes this column worth reading before any alert exists (AF-02).
        $this->assertSame(2, $rows['Rep']['correction_count']);
    }

    // -----------------------------------------------------------------
    // Tenancy
    // -----------------------------------------------------------------

    public function test_no_report_ever_shows_another_stores_numbers(): void
    {
        $other = Merchant::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create(['name' => 'Other Store Branch']);
        $otherUser = User::factory()->owner($other->id)->create(['name' => 'Other Owner']);

        app(TenantContext::class)->for($other->id, function () use ($other, $otherBranch, $otherUser) {
            $customer = Customer::create(['phone' => '0980000000', 'name' => 'Their Customer']);

            Invoice::create([
                'branch_id' => $otherBranch->id,
                'user_id' => $otherUser->id,
                'customer_id' => $customer->id,
                'invoice_number' => 'THEIR-1',
                'amount' => 5000,
                'invoice_date' => now()->toDateString(),
            ]);
        });

        $this->sale(['amount' => 300]);

        $this->report('summary', $this->owner)
            ->assertOk()
            ->assertJsonPath('data.sales_total', '300.00')
            ->assertJsonPath('data.new_customers', 1);

        $branches = collect($this->report('branches', $this->owner)->assertOk()->json('data'))
            ->pluck('branch');
        $this->assertFalse($branches->contains('Other Store Branch'));

        $staff = collect($this->report('staff', $this->owner)->assertOk()->json('data'))
            ->pluck('name');
        $this->assertFalse($staff->contains('Other Owner'));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function seedRedemption(
        float $discount,
        ?float $computed = null,
        bool $override = false,
        RewardType $type = RewardType::Percentage,
    ): \App\Models\Redemption {
        return \App\Models\Redemption::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->damascus->id,
            'loyalty_rule_id' => LoyaltyRule::withoutGlobalScopes()->first()->id,
            'cycle_number' => 1,
            'cycle_total_amount' => 1000,
            'cycle_invoice_count' => 1,
            'reward_type' => $type,
            'computed_amount' => $computed ?? $discount,
            'discount_amount' => $discount,
            'carried_over_amount' => 0,
            'performed_by' => $this->manager->id,
            'is_override' => $override,
            'override_reason' => $override ? 'Agreed with the customer.' : null,
            'override_approved_by' => $override ? $this->owner->id : null,
            'redeemed_at' => now(),
        ]);
    }

    private function seedVoucher(
        \App\Models\Redemption $redemption,
        float $amount,
        ?\Carbon\CarbonInterface $expiresAt = null,
        ?\Carbon\CarbonInterface $usedAt = null,
    ): void {
        static $sequence = 0;
        $sequence++;

        \App\Models\Voucher::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'redemption_id' => $redemption->id,
            'code' => 'CODE' . $sequence,
            'amount' => $amount,
            'status' => $usedAt !== null ? 'used' : 'issued',
            'issued_at' => now(),
            'expires_at' => $expiresAt ?? now()->addDays(30),
            'used_at' => $usedAt,
        ]);
    }
}
