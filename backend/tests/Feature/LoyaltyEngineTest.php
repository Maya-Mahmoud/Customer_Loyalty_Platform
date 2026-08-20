<?php

namespace Tests\Feature;

use App\Enums\AccumulationScope;
use App\Enums\LedgerEntryType;
use App\Enums\ResetPolicy;
use App\Enums\RewardType;
use App\Enums\ThresholdType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Services\Loyalty\LoyaltyEngine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rule engine of BRD 11.
 *
 * The first case walks the worked example of BRD 11.2 invoice by invoice, because
 * that table is the clearest statement anyone wrote of what the system is supposed
 * to do. The rest cover one business rule each, as BRD 20 requires.
 */
class LoyaltyEngineTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private Customer $customer;

    private LoyaltyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();

        // The engine reads through the tenant scope, exactly as a request would.
        app(TenantContext::class)->set($this->merchant->id);

        $this->customer = Customer::create([
            'phone' => '0991234567',
            'name' => 'Test Customer',
        ]);

        $this->engine = app(LoyaltyEngine::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function rule(array $overrides = []): LoyaltyRule
    {
        return LoyaltyRule::create([
            ...LoyaltyRule::defaults(),
            'effective_from' => now()->subMonth()->toDateString(),
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * Records a qualifying purchase the way the invoice flow will: one accrual
     * entry, never a stored balance (BRD 13.3).
     */
    private function accrue(float $amount, ?Branch $branch = null): void
    {
        LedgerEntry::create([
            'customer_id' => $this->customer->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'cycle_number' => $this->customer->current_cycle_number,
            'type' => LedgerEntryType::Accrual,
            'amount' => $amount,
            'invoice_count_delta' => 1,
        ]);
    }

    // -----------------------------------------------------------------
    // The worked example of BRD 11.2
    // -----------------------------------------------------------------

    public function test_the_worked_example_from_the_brd_reproduces_exactly(): void
    {
        // Threshold 1,000 by amount, 10% capped at 50, minimum invoice 10,
        // merchant-wide accumulation, surplus carried over.
        $rule = $this->rule();
        $aleppo = Branch::factory()->for($this->merchant)->create(['name' => 'Aleppo Branch']);
        $latakia = Branch::factory()->for($this->merchant)->create(['name' => 'Latakia Branch']);

        // Invoice 1 — Damascus, 240. Not eligible, 760 to go.
        $this->accrue(240);
        $snapshot = $this->engine->snapshot($this->customer, $rule);
        $this->assertSame(240.0, $snapshot->totalAmount);
        $this->assertSame(760.0, $snapshot->amountRemaining());
        $this->assertFalse($snapshot->isEligible());

        // Invoice 2 — Aleppo, 8. Under the minimum, so it never reaches the
        // ledger at all (BR-003). The total is unchanged.
        $this->assertFalse($this->engine->qualifies($rule, 8));
        $snapshot = $this->engine->snapshot($this->customer, $rule);
        $this->assertSame(240.0, $snapshot->totalAmount);

        // Invoice 3 — Damascus, 400. Total 640, 360 to go.
        $this->accrue(400);
        $snapshot = $this->engine->snapshot($this->customer, $rule);
        $this->assertSame(640.0, $snapshot->totalAmount);
        $this->assertSame(360.0, $snapshot->amountRemaining());

        // Invoice 4 — Latakia, 520. Total 1,160 across three branches, eligible.
        $this->accrue(520, $latakia);
        $snapshot = $this->engine->snapshot($this->customer, $rule);
        $this->assertSame(1160.0, $snapshot->totalAmount);
        $this->assertTrue($snapshot->isEligible());
        $this->assertSame(0.0, $snapshot->amountRemaining());

        // Redemption — 10% of 1,160 is 116, capped to 50; surplus 160 carries over.
        $reward = $this->engine->reward($snapshot);
        $this->assertSame(116.0, $reward->computedAmount);
        $this->assertSame(50.0, $reward->discountAmount);
        $this->assertSame(160.0, $reward->carriedOverAmount);
        $this->assertTrue($reward->wasCapped());
    }

    public function test_the_next_cycle_opens_with_the_carried_surplus(): void
    {
        $rule = $this->rule();

        $this->accrue(1160);
        $reward = $this->engine->reward($this->engine->snapshot($this->customer, $rule));

        // Closing the cycle and opening the next is the redemption flow's job; here
        // the carried figure is written the way it will be, and read back.
        $this->customer->increment('current_cycle_number');
        LedgerEntry::create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'cycle_number' => $this->customer->current_cycle_number,
            'type' => LedgerEntryType::CarryOver,
            'amount' => $reward->carriedOverAmount,
            'invoice_count_delta' => 0,
        ]);

        // Invoice 5 of the example — 140 on top of the carried 160 gives 300.
        $this->accrue(140);

        $snapshot = $this->engine->snapshot($this->customer->fresh(), $rule);

        $this->assertSame(2, $snapshot->cycleNumber);
        $this->assertSame(300.0, $snapshot->totalAmount);
        $this->assertSame(700.0, $snapshot->amountRemaining());
    }

    // -----------------------------------------------------------------
    // BR-003 — the minimum invoice
    // -----------------------------------------------------------------

    public function test_an_invoice_below_the_minimum_does_not_qualify(): void
    {
        $rule = $this->rule(['min_invoice_amount' => 10]);

        // The bar stops a sale being split into fragments to manufacture visits.
        $this->assertFalse($this->engine->qualifies($rule, 9.99));
        $this->assertTrue($this->engine->qualifies($rule, 10));
        $this->assertTrue($this->engine->qualifies($rule, 10.01));
    }

    // -----------------------------------------------------------------
    // BR-006 — carry over versus full reset
    // -----------------------------------------------------------------

    public function test_a_full_reset_rule_carries_nothing(): void
    {
        $rule = $this->rule(['reset_policy' => ResetPolicy::FullReset]);

        $this->accrue(1160);

        $reward = $this->engine->reward($this->engine->snapshot($this->customer, $rule));

        $this->assertSame(0.0, $reward->carriedOverAmount);
        $this->assertSame(0, $reward->carriedOverInvoices);
    }

    // -----------------------------------------------------------------
    // BR-021 — the absolute cap
    // -----------------------------------------------------------------

    public function test_the_cap_only_applies_to_a_percentage(): void
    {
        // A flat amount is already bounded by its own value, so the ceiling of
        // BR-021 has nothing to bite on.
        $fixed = $this->rule([
            'reward_type' => RewardType::FixedAmount,
            'reward_value' => 75,
            'max_discount_amount' => 50,
        ]);

        $this->accrue(5000);

        $reward = $this->engine->reward($this->engine->snapshot($this->customer, $fixed));

        $this->assertSame(75.0, $reward->discountAmount);
        $this->assertFalse($reward->wasCapped());
    }

    public function test_a_voucher_pays_the_amount_the_owner_set(): void
    {
        // A voucher is spending credit of a fixed value, so it computes like a
        // fixed amount and is not scaled by the cycle.
        $rule = $this->rule([
            'reward_type' => RewardType::Voucher,
            'reward_value' => 50,
            'max_discount_amount' => null,
        ]);

        $this->accrue(3000);

        $reward = $this->engine->reward($this->engine->snapshot($this->customer, $rule));

        $this->assertSame(RewardType::Voucher, $reward->rewardType);
        $this->assertSame(50.0, $reward->discountAmount);
    }

    // -----------------------------------------------------------------
    // BR-009 — reversals are ordinary negative entries
    // -----------------------------------------------------------------

    public function test_a_reversal_lowers_the_cycle_and_can_remove_eligibility(): void
    {
        $rule = $this->rule();

        $this->accrue(1100);
        $this->assertTrue($this->engine->snapshot($this->customer, $rule)->isEligible());

        // Cancelling an invoice needs no special case: it is a negative row, so the
        // sum simply falls (BR-009). This is what stops fake invoices being entered
        // and cancelled after the customer has benefited.
        LedgerEntry::create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'cycle_number' => $this->customer->current_cycle_number,
            'type' => LedgerEntryType::Reversal,
            'amount' => -300,
            'invoice_count_delta' => -1,
        ]);

        $snapshot = $this->engine->snapshot($this->customer, $rule);

        $this->assertSame(800.0, $snapshot->totalAmount);
        $this->assertFalse($snapshot->isEligible());
    }

    // -----------------------------------------------------------------
    // FR-LOY-01 — the three threshold types
    // -----------------------------------------------------------------

    public function test_an_invoice_count_threshold_ignores_the_amount(): void
    {
        $rule = $this->rule([
            'threshold_type' => ThresholdType::InvoiceCount,
            'threshold_amount' => null,
            'threshold_invoice_count' => 3,
        ]);

        $this->accrue(20);
        $this->accrue(20);

        $snapshot = $this->engine->snapshot($this->customer, $rule);
        $this->assertSame(1, $snapshot->invoicesRemaining());
        $this->assertNull($snapshot->amountRemaining());
        $this->assertFalse($snapshot->isEligible());

        $this->accrue(20);
        $this->assertTrue($this->engine->snapshot($this->customer, $rule)->isEligible());
    }

    public function test_a_combined_threshold_requires_both_conditions(): void
    {
        $rule = $this->rule([
            'threshold_type' => ThresholdType::Both,
            'threshold_amount' => 500,
            'threshold_invoice_count' => 3,
        ]);

        // Money reached in a single visit — the "both" option tightens the bar, so
        // one is not enough.
        $this->accrue(600);
        $this->assertFalse($this->engine->snapshot($this->customer, $rule)->isEligible());

        $this->accrue(10);
        $this->accrue(10);
        $this->assertTrue($this->engine->snapshot($this->customer, $rule)->isEligible());
    }

    // -----------------------------------------------------------------
    // FR-LOY-05 — accumulation scope
    // -----------------------------------------------------------------

    public function test_merchant_wide_accumulation_pools_every_branch(): void
    {
        // BRD BR-016: the customer deals with the brand, not the branch.
        $rule = $this->rule(['accumulation_scope' => AccumulationScope::Merchant]);
        $other = Branch::factory()->for($this->merchant)->create(['name' => 'Second Branch']);

        $this->accrue(600);
        $this->accrue(600, $other);

        $snapshot = $this->engine->snapshot($this->customer, $rule, $this->branch->id);

        $this->assertSame(1200.0, $snapshot->totalAmount);
        $this->assertTrue($snapshot->isEligible());
    }

    public function test_branch_scoped_accumulation_counts_one_branch_only(): void
    {
        $rule = $this->rule(['accumulation_scope' => AccumulationScope::Branch]);
        $other = Branch::factory()->for($this->merchant)->create(['name' => 'Second Branch']);

        $this->accrue(600);
        $this->accrue(600, $other);

        $here = $this->engine->snapshot($this->customer, $rule, $this->branch->id);

        $this->assertSame(600.0, $here->totalAmount);
        $this->assertFalse($here->isEligible());
    }

    // -----------------------------------------------------------------
    // The customer card (FR-CUS-05, FR-CUS-06)
    // -----------------------------------------------------------------

    public function test_progress_reports_the_condition_still_holding_the_customer_back(): void
    {
        $rule = $this->rule([
            'threshold_type' => ThresholdType::Both,
            'threshold_amount' => 1000,
            'threshold_invoice_count' => 10,
        ]);

        // 90% of the money but only 10% of the visits: the bar must show the visits.
        $this->accrue(900);

        $snapshot = $this->engine->snapshot($this->customer, $rule);

        $this->assertSame(0.1, round($snapshot->progress(), 2));
    }

    public function test_progress_never_exceeds_one(): void
    {
        $rule = $this->rule();

        $this->accrue(4000);

        $this->assertSame(1.0, $this->engine->snapshot($this->customer, $rule)->progress());
    }

    public function test_a_merchant_with_no_rule_yields_no_snapshot(): void
    {
        $this->assertNull($this->engine->snapshot($this->customer));
    }

    public function test_an_ineligible_cycle_refuses_to_produce_a_reward(): void
    {
        $rule = $this->rule();

        $this->accrue(400);

        // Paying out early is the owner-approved override of BR-014, a deliberate
        // decision in the redemption flow — never a silent outcome here.
        $this->expectException(\RuntimeException::class);

        $this->engine->reward($this->engine->snapshot($this->customer, $rule));
    }
}
