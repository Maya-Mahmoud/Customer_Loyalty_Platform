<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\MerchantStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Writing off balances nobody came back for (BRD BR-017).
 *
 * This is the one operation in the system that takes value away from a customer
 * without anybody pressing a button, so the cases below are mostly about restraint:
 * what it must not touch, and what it must not do twice.
 */
class BalanceExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create(['status' => MerchantStatus::Active]);
        $this->branch = Branch::factory()->for($this->merchant)->create();

        app(TenantContext::class)->set($this->merchant->id);

        $this->customer = Customer::create([
            'phone' => '0991234567',
            'name' => 'Sami',
            'registered_at_branch_id' => $this->branch->id,
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
            'effective_from' => now()->subYears(3)->toDateString(),
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function accrue(float $amount, int $invoices = 1, ?Customer $customer = null): void
    {
        $customer ??= $this->customer;

        LedgerEntry::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $customer->getKey(),
            'branch_id' => $this->branch->id,
            'cycle_number' => $customer->fresh()->current_cycle_number,
            'type' => LedgerEntryType::Accrual,
            'amount' => $amount,
            'invoice_count_delta' => $invoices,
        ]);
    }

    private function lastPurchase(\Carbon\CarbonInterface $when, ?Customer $customer = null): void
    {
        ($customer ?? $this->customer)->forceFill(['last_purchase_at' => $when])->saveQuietly();
    }

    private function ledgerSum(?Customer $customer = null): float
    {
        return (float) LedgerEntry::withoutGlobalScopes()
            ->where('customer_id', ($customer ?? $this->customer)->getKey())
            ->sum('amount');
    }

    private function expire(array $options = []): void
    {
        $this->artisan('balances:expire', $options)->assertSuccessful();
    }

    // -----------------------------------------------------------------

    public function test_a_balance_untouched_past_the_window_is_written_off(): void
    {
        // Twelve months by default (BRD FR-LOY-08).
        $this->publishRule();
        $this->accrue(600, invoices: 2);
        $this->lastPurchase(now()->subMonths(13));

        $this->expire();

        $entry = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::Expiry)->sole();
        $this->assertSame('-600.00', $entry->amount);
        $this->assertSame(-2, $entry->invoice_count_delta);
        $this->assertSame(1, $entry->cycle_number);
        $this->assertNotNull($entry->note);

        // The invariant holds: the entries still sum to the balance, which is zero.
        $this->assertSame(0.0, $this->ledgerSum());

        // A new cycle is open, which is what stops the same balance expiring twice.
        $this->assertSame(2, $this->customer->fresh()->current_cycle_number);
    }

    public function test_a_customer_who_bought_inside_the_window_keeps_everything(): void
    {
        $this->publishRule();
        $this->accrue(600);
        $this->lastPurchase(now()->subMonths(11));

        $this->expire();

        $this->assertSame(0, LedgerEntry::withoutGlobalScopes()->where('type', LedgerEntryType::Expiry)->count());
        $this->assertSame(600.0, $this->ledgerSum());
        $this->assertSame(1, $this->customer->fresh()->current_cycle_number);
    }

    public function test_running_twice_writes_off_once(): void
    {
        $this->publishRule();
        $this->accrue(600);
        $this->lastPurchase(now()->subMonths(13));

        $this->expire();
        $this->expire();

        $this->assertSame(1, LedgerEntry::withoutGlobalScopes()->where('type', LedgerEntryType::Expiry)->count());
        $this->assertSame(0.0, $this->ledgerSum());
    }

    public function test_a_rule_with_no_validity_window_never_expires_anything(): void
    {
        // The owner's choice, and FR-LOY-08 allows it.
        $this->publishRule(['balance_validity_months' => null]);
        $this->accrue(600);
        $this->lastPurchase(now()->subYears(2));

        $this->expire();

        $this->assertSame(600.0, $this->ledgerSum());
    }

    public function test_an_empty_balance_produces_no_entry(): void
    {
        $this->publishRule();
        $this->lastPurchase(now()->subYears(2));

        $this->expire();

        // An entry worth zero would still be a movement, and the reconciliation of
        // BRD 20 would have to filter it out.
        $this->assertSame(0, LedgerEntry::withoutGlobalScopes()->count());
    }

    public function test_a_customer_who_never_bought_is_left_alone(): void
    {
        $this->publishRule();

        // No last_purchase_at at all: nothing to expire, rather than infinitely
        // stale. Their record was created and never used.
        $this->assertNull($this->customer->last_purchase_at);

        $this->expire();

        $this->assertSame(1, $this->customer->fresh()->current_cycle_number);
        $this->assertSame(0, LedgerEntry::withoutGlobalScopes()->count());
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $this->publishRule();
        $this->accrue(600);
        $this->lastPurchase(now()->subMonths(13));

        $this->artisan('balances:expire', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(600.0, $this->ledgerSum());
        $this->assertSame(1, $this->customer->fresh()->current_cycle_number);
    }

    public function test_a_suspended_store_is_skipped(): void
    {
        $this->publishRule();
        $this->accrue(600);
        $this->lastPurchase(now()->subMonths(13));

        $this->merchant->forceFill(['status' => MerchantStatus::Suspended])->save();

        $this->expire();

        // Their customers should not lose balances while the store cannot even open
        // the screen to explain it.
        $this->assertSame(600.0, $this->ledgerSum());
    }

    public function test_each_store_is_expired_by_its_own_rule(): void
    {
        // Twelve months here.
        $this->publishRule();
        $this->accrue(600);
        $this->lastPurchase(now()->subMonths(13));

        // Twenty-four months there, so the same age survives.
        $other = Merchant::factory()->create(['status' => MerchantStatus::Active]);
        $otherBranch = Branch::factory()->for($other)->create();

        LoyaltyRule::withoutGlobalScopes()->create([
            ...LoyaltyRule::defaults(),
            'merchant_id' => $other->id,
            'effective_from' => now()->subYears(3)->toDateString(),
            'is_active' => true,
            'balance_validity_months' => 24,
        ]);

        $theirCustomer = app(TenantContext::class)->for($other->id, fn () => Customer::create([
            'phone' => '0980000000',
            'name' => 'Their Customer',
            'last_purchase_at' => now()->subMonths(13),
        ]));

        LedgerEntry::withoutGlobalScopes()->create([
            'merchant_id' => $other->id,
            'customer_id' => $theirCustomer->id,
            'branch_id' => $otherBranch->id,
            'cycle_number' => 1,
            'type' => LedgerEntryType::Accrual,
            'amount' => 900,
            'invoice_count_delta' => 1,
        ]);

        $this->expire();

        $this->assertSame(0.0, $this->ledgerSum());
        $this->assertSame(900.0, $this->ledgerSum($theirCustomer));
    }

    public function test_the_customer_card_shows_the_balance_gone_after_expiry(): void
    {
        $this->publishRule();
        $this->accrue(600);
        $this->lastPurchase(now()->subMonths(13));

        $this->expire();

        $rep = User::factory()->salesRep($this->branch)->create();

        $this->actingAs($rep, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('customer.cycle.total_amount', 0)
            ->assertJsonPath('customer.cycle.invoice_count', 0)
            ->assertJsonPath('customer.current_cycle_number', 2);
    }
}
