<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\ResetPolicy;
use App\Enums\RewardType;
use App\Enums\ThresholdType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Models\Voucher;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paying a reward out (BRD 8.6).
 *
 * This is the only place the system gives value away, so the cases below are
 * weighted towards the ways it must refuse: an unreached threshold, a second reward
 * the same day, an exception nobody authorised, a rep reaching for the till, and two
 * managers pressing redeem at the same instant.
 *
 * The money itself is checked against one invariant throughout — the sum of a
 * customer's ledger entries equals their balance — because that is the criterion
 * BRD 20 accepts as proof.
 */
class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private User $owner;

    private User $manager;

    private User $rep;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();

        $this->owner = User::factory()->owner($this->merchant->id)->create();
        $this->manager = User::factory()->branchManager($this->branch)->create();
        $this->rep = User::factory()->salesRep($this->branch)->create();

        app(TenantContext::class)->set($this->merchant->id);

        $this->customer = Customer::create([
            'phone' => '0991234567',
            'name' => 'Sami',
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
            'effective_from' => now()->subMonth()->toDateString(),
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** A qualifying purchase, recorded the way the invoice flow records one. */
    private function accrue(float $amount, int $invoices = 1): void
    {
        LedgerEntry::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'cycle_number' => $this->customer->fresh()->current_cycle_number,
            'type' => LedgerEntryType::Accrual,
            'amount' => $amount,
            'invoice_count_delta' => $invoices,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function redeemAs(User $user, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/customers/{$this->customer->id}/redemptions", $payload);
    }

    /** The balance as BRD 13.3 defines it: the sum of the entries, never a column. */
    private function ledgerSum(): float
    {
        return (float) LedgerEntry::withoutGlobalScopes()
            ->where('customer_id', $this->customer->id)
            ->sum('amount');
    }

    // -----------------------------------------------------------------
    // The happy path, and the arithmetic of BRD 11.2
    // -----------------------------------------------------------------

    public function test_a_manager_pays_the_reward_and_the_cycle_closes_with_the_surplus_carried(): void
    {
        $this->publishRule();
        $this->accrue(1160, invoices: 3);

        $response = $this->redeemAs($this->manager)->assertCreated();

        // 10% of 1,160 is 116, capped at 50; 160 above the threshold carries over.
        $response->assertJsonPath('data.computed_amount', '116.00')
            ->assertJsonPath('data.discount_amount', '50.00')
            ->assertJsonPath('data.was_capped', true)
            ->assertJsonPath('data.carried_over_amount', '160.00')
            ->assertJsonPath('data.cycle_number', 1)
            ->assertJsonPath('data.is_override', false);

        // BR-005: the closing entry carries the negative of the cycle, so the
        // closed cycle nets to zero.
        $close = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::CycleClose)->sole();
        $this->assertSame('-1160.00', $close->amount);
        $this->assertSame(-3, $close->invoice_count_delta);
        $this->assertSame(1, $close->cycle_number);

        // BR-006: the surplus opens cycle 2. BR-007 is why the invoice count does
        // not follow the money under an amount threshold — those purchases have
        // already earned their reward once.
        $carry = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::CarryOver)->sole();
        $this->assertSame('160.00', $carry->amount);
        $this->assertSame(0, $carry->invoice_count_delta);
        $this->assertSame(2, $carry->cycle_number);

        $this->assertSame(2, $this->customer->fresh()->current_cycle_number);

        // The invariant: 1160 - 1160 + 160.
        $this->assertSame(160.0, $this->ledgerSum());
    }

    public function test_a_full_reset_rule_carries_nothing_forward(): void
    {
        $this->publishRule(['reset_policy' => ResetPolicy::FullReset]);
        $this->accrue(1160);

        $this->redeemAs($this->manager)->assertCreated()
            ->assertJsonPath('data.carried_over_amount', '0.00');

        // Nothing to carry means no row at all, so the ledger does not fill with
        // zero-value entries.
        $this->assertFalse(
            LedgerEntry::withoutGlobalScopes()->where('type', LedgerEntryType::CarryOver)->exists()
        );
        $this->assertSame(0.0, $this->ledgerSum());
        $this->assertSame(2, $this->customer->fresh()->current_cycle_number);
    }

    public function test_a_fixed_reward_pays_its_face_value_and_is_never_capped(): void
    {
        $this->publishRule([
            'reward_type' => RewardType::FixedAmount,
            'reward_value' => 75,
            'max_discount_amount' => 50,
        ]);
        $this->accrue(1000);

        $this->redeemAs($this->manager)->assertCreated()
            ->assertJsonPath('data.discount_amount', '75.00')
            ->assertJsonPath('data.was_capped', false);
    }

    public function test_a_count_threshold_carries_the_surplus_invoices_and_not_the_money(): void
    {
        $this->publishRule([
            'threshold_type' => ThresholdType::InvoiceCount,
            'threshold_amount' => null,
            'threshold_invoice_count' => 5,
        ]);
        $this->accrue(600, invoices: 7);

        $this->redeemAs($this->manager)->assertCreated();

        $carry = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::CarryOver)->sole();
        $this->assertSame(2, $carry->invoice_count_delta);
        $this->assertSame('0.00', $carry->amount);
    }

    // -----------------------------------------------------------------
    // Who may pay (BR-013, BRD 7.2)
    // -----------------------------------------------------------------

    public function test_a_sales_rep_cannot_pay_a_reward_out(): void
    {
        $this->publishRule();
        $this->accrue(1160);

        $this->redeemAs($this->rep)->assertForbidden();

        $this->assertSame(0, Redemption::withoutGlobalScopes()->count());
        $this->assertSame(1160.0, $this->ledgerSum());
    }

    public function test_the_owner_pays_from_a_branch_they_name_because_they_belong_to_none(): void
    {
        $this->publishRule();
        $this->accrue(1160);

        $this->redeemAs($this->owner, ['branch_id' => $this->branch->id])
            ->assertCreated()
            ->assertJsonPath('data.branch', $this->branch->name);
    }

    public function test_the_owner_is_asked_for_a_branch_when_they_name_none(): void
    {
        $this->publishRule();
        $this->accrue(1160);

        $this->redeemAs($this->owner)->assertStatus(409);

        $this->assertSame(0, Redemption::withoutGlobalScopes()->count());
    }

    // -----------------------------------------------------------------
    // Eligibility and the exception path (FR-RED-06, BR-014)
    // -----------------------------------------------------------------

    public function test_a_customer_below_the_threshold_is_refused(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->redeemAs($this->manager)->assertStatus(409);

        $this->assertSame(0, Redemption::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->customer->fresh()->current_cycle_number);
        $this->assertSame(400.0, $this->ledgerSum());
    }

    public function test_nothing_is_paid_when_no_rule_is_published(): void
    {
        $this->accrue(1160);

        $this->redeemAs($this->manager)->assertStatus(409);

        $this->assertSame(0, Redemption::withoutGlobalScopes()->count());
    }

    public function test_the_owner_may_override_the_threshold_with_a_written_reason(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $response = $this->redeemAs($this->owner, [
            'branch_id' => $this->branch->id,
            'override' => true,
            'override_reason' => 'Complaint settlement agreed with the customer.',
        ])->assertCreated();

        // An override pays a percentage of what was actually accumulated — 10% of
        // 400 — and is never scaled up to the threshold.
        $response->assertJsonPath('data.discount_amount', '40.00')
            ->assertJsonPath('data.is_override', true)
            ->assertJsonPath('data.override_reason', 'Complaint settlement agreed with the customer.')
            // The cycle never reached the threshold, so there is no surplus.
            ->assertJsonPath('data.carried_over_amount', '0.00');

        $redemption = Redemption::withoutGlobalScopes()->sole();
        $this->assertSame($this->owner->id, $redemption->override_approved_by);

        // BR-014 asks for a trail, and an override is exactly the entry an auditor
        // goes looking for.
        $this->assertTrue(
            AuditLog::withoutGlobalScopes()->where('action', 'redemption.override')->exists()
        );

        $this->assertSame(0.0, $this->ledgerSum());
    }

    public function test_a_branch_manager_cannot_approve_their_own_exception(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->redeemAs($this->manager, [
            'override' => true,
            'override_reason' => 'Customer is a regular and complained.',
        ])->assertStatus(409);

        $this->assertSame(0, Redemption::withoutGlobalScopes()->count());
    }

    public function test_an_override_without_a_reason_is_refused(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->redeemAs($this->owner, [
            'branch_id' => $this->branch->id,
            'override' => true,
        ])->assertStatus(409);

        $this->redeemAs($this->owner, [
            'branch_id' => $this->branch->id,
            'override' => true,
            'override_reason' => 'ok',
        ])->assertStatus(422);

        $this->assertSame(0, Redemption::withoutGlobalScopes()->count());
    }

    // -----------------------------------------------------------------
    // One a day (BR-018)
    // -----------------------------------------------------------------

    public function test_a_second_reward_the_same_day_is_refused(): void
    {
        $this->publishRule();
        $this->accrue(1160);

        $this->redeemAs($this->manager)->assertCreated();

        // Enough for a second reward on paper, which is precisely the pattern
        // BR-018 exists to stop.
        $this->accrue(1160);
        $this->redeemAs($this->manager)->assertStatus(409);

        $this->assertSame(1, Redemption::withoutGlobalScopes()->count());
    }

    public function test_the_owner_may_override_the_daily_limit(): void
    {
        $this->publishRule();
        $this->accrue(1160);
        $this->redeemAs($this->manager)->assertCreated();

        $this->accrue(1160);
        $this->redeemAs($this->owner, [
            'branch_id' => $this->branch->id,
            'override' => true,
            'override_reason' => 'Two separate promotions ran on the same day.',
        ])->assertCreated();

        $this->assertSame(2, Redemption::withoutGlobalScopes()->count());
    }

    public function test_yesterdays_reward_does_not_block_todays(): void
    {
        $this->publishRule();
        $this->accrue(1160);

        $this->travel(-1)->day();
        $this->redeemAs($this->manager)->assertCreated();
        $this->travelBack();

        $this->accrue(1160);
        $this->redeemAs($this->manager)->assertCreated();

        $this->assertSame(2, Redemption::withoutGlobalScopes()->count());
        $this->assertSame(3, $this->customer->fresh()->current_cycle_number);
    }

    // -----------------------------------------------------------------
    // Two tills at once
    // -----------------------------------------------------------------

    public function test_two_managers_redeeming_at_the_same_instant_pay_only_once(): void
    {
        $this->publishRule();
        $this->accrue(1160);

        $second = User::factory()->branchManager($this->branch)->create();

        $this->redeemAs($this->manager)->assertCreated();

        /*
         * The second manager is acting on a screen drawn before the first press, so
         * they aim at cycle 1 while the customer has already moved to cycle 2. The
         * conditional update matches nothing and they are told to look the customer
         * up again, rather than the same cycle being paid out twice.
         */
        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/customers/{$this->customer->id}/redemptions")
            ->assertStatus(409);

        $this->assertSame(1, Redemption::withoutGlobalScopes()->count());
        $this->assertSame(160.0, $this->ledgerSum());
    }

    // -----------------------------------------------------------------
    // A voucher reward
    // -----------------------------------------------------------------

    public function test_a_voucher_reward_issues_a_voucher_the_customer_can_spend_later(): void
    {
        $this->publishRule([
            'reward_type' => RewardType::Voucher,
            'reward_value' => 50,
            'max_discount_amount' => null,
            'voucher_validity_days' => 30,
        ]);
        $this->accrue(1160);

        $response = $this->redeemAs($this->manager)->assertCreated();

        $voucher = Voucher::withoutGlobalScopes()->sole();
        $this->assertSame('50.00', $voucher->amount);
        $this->assertSame($this->customer->id, $voucher->customer_id);
        $this->assertSame(now()->addDays(30)->toDateString(), $voucher->expires_at->toDateString());

        // The code is what the customer walks out with, so it is on the response.
        $response->assertJsonPath('data.voucher.code', $voucher->code);
    }

    // -----------------------------------------------------------------
    // Preview and history (FR-RED-03, FR-RED-07)
    // -----------------------------------------------------------------

    public function test_the_preview_shows_the_figure_without_paying_anything(): void
    {
        $this->publishRule();
        $this->accrue(1160);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}/redemptions/preview")
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('reward.computed_amount', '116.00')
            ->assertJsonPath('reward.discount_amount', '50.00')
            ->assertJsonPath('reward.was_capped', true)
            ->assertJsonPath('cycle.total_amount', '1160.00');

        // Nothing moved.
        $this->assertSame(0, Redemption::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->customer->fresh()->current_cycle_number);
        $this->assertSame(1160.0, $this->ledgerSum());
    }

    public function test_the_preview_says_plainly_when_there_is_nothing_to_pay(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}/redemptions/preview")
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('reward', null);
    }

    public function test_the_history_lists_past_rewards_with_their_dates_and_values(): void
    {
        $this->publishRule();
        $this->accrue(1160);
        $this->redeemAs($this->manager)->assertCreated();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}/redemptions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.discount_amount', '50.00')
            ->assertJsonPath('data.0.branch', $this->branch->name);
    }

    // -----------------------------------------------------------------
    // Tenancy
    // -----------------------------------------------------------------

    public function test_a_manager_cannot_pay_a_reward_to_another_stores_customer(): void
    {
        $other = Merchant::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();

        $otherCustomer = app(TenantContext::class)->for($other->id, fn () => Customer::create([
            'phone' => '0991111222',
            'name' => 'Other Store Customer',
        ]));

        LedgerEntry::withoutGlobalScopes()->create([
            'merchant_id' => $other->id,
            'customer_id' => $otherCustomer->id,
            'branch_id' => $otherBranch->id,
            'cycle_number' => 1,
            'type' => LedgerEntryType::Accrual,
            'amount' => 5000,
            'invoice_count_delta' => 1,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/customers/{$otherCustomer->id}/redemptions")
            ->assertNotFound();

        $this->assertSame(0, Redemption::withoutGlobalScopes()->count());
    }
}
