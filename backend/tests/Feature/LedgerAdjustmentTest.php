<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Models\AuditLog;
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
 * Correcting a balance by hand (BRD 7.2, ledger.adjust).
 *
 * The escape hatch, and the tests hold it to the price of using it: the owner alone,
 * a written reason, an audit entry, and never more taken away than the customer
 * actually has. Its absence is what makes staff invent fake invoices (AF-01), so it
 * has to exist — and it has to be the more expensive option of the two.
 */
class LedgerAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private User $owner;

    private User $manager;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();

        $this->owner = User::factory()->owner($this->merchant->id)->create();
        $this->manager = User::factory()->branchManager($this->branch)->create();

        app(TenantContext::class)->set($this->merchant->id);

        $this->customer = Customer::create([
            'phone' => '0991234567',
            'name' => 'Sami',
        ]);

        app(TenantContext::class)->forget();
    }

    private function publishRule(): LoyaltyRule
    {
        return LoyaltyRule::withoutGlobalScopes()->create([
            ...LoyaltyRule::defaults(),
            'merchant_id' => $this->merchant->id,
            'effective_from' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function accrue(float $amount): void
    {
        LedgerEntry::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'cycle_number' => 1,
            'type' => LedgerEntryType::Accrual,
            'amount' => $amount,
            'invoice_count_delta' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function adjustAs(User $user, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/customers/{$this->customer->id}/adjustments", [
                'branch_id' => $this->branch->id,
                ...$payload,
            ]);
    }

    private function ledgerSum(): float
    {
        return (float) LedgerEntry::withoutGlobalScopes()
            ->where('customer_id', $this->customer->id)
            ->sum('amount');
    }

    // -----------------------------------------------------------------

    public function test_the_owner_can_add_to_a_balance_with_a_written_reason(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->adjustAs($this->owner, [
            'amount' => 150,
            'reason' => 'System was down during the sale; entered from the paper receipt.',
        ])
            ->assertCreated()
            ->assertJsonPath('balance', 550);

        $entry = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::ManualAdjustment)->sole();
        $this->assertSame('150.00', $entry->amount);
        // The visit count is untouched: an adjustment moves money, and inventing a
        // visit would let a count threshold be reached without anyone walking in.
        $this->assertSame(0, $entry->invoice_count_delta);
        $this->assertSame($this->owner->id, $entry->created_by);

        $this->assertSame(550.0, $this->ledgerSum());

        $log = AuditLog::withoutGlobalScopes()->where('action', 'ledger.adjusted')->sole();
        // JSON round-trips a whole number as an int, so the value is compared loosely.
        $this->assertEquals(400, $log->before['balance']);
        $this->assertEquals(550, $log->after['balance']);
    }

    public function test_the_owner_can_deduct_from_a_balance(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->adjustAs($this->owner, [
            'amount' => -100,
            'reason' => 'Entered twice by mistake; removing the duplicate.',
        ])
            ->assertCreated()
            ->assertJsonPath('balance', 300);

        $this->assertSame(300.0, $this->ledgerSum());
    }

    public function test_a_deduction_cannot_exceed_what_the_customer_has(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->adjustAs($this->owner, [
            'amount' => -500,
            'reason' => 'Trying to take out more than is there.',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        // No negative balance the customer never spent.
        $this->assertSame(400.0, $this->ledgerSum());
    }

    public function test_a_branch_manager_cannot_adjust_a_balance_at_all(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->adjustAs($this->manager, [
            'amount' => 100,
            'reason' => 'A manager should not be able to do this.',
        ])->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}/adjustments")
            ->assertForbidden();

        $this->assertSame(400.0, $this->ledgerSum());
    }

    public function test_a_reason_is_required_and_has_to_say_something(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->adjustAs($this->owner, ['amount' => 100, 'reason' => 'تصحيح'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->adjustAs($this->owner, ['amount' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(400.0, $this->ledgerSum());
    }

    public function test_an_adjustment_of_zero_is_refused(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->adjustAs($this->owner, [
            'amount' => 0,
            'reason' => 'A movement of nothing is not a movement.',
        ])->assertStatus(422);

        $this->assertSame(1, LedgerEntry::withoutGlobalScopes()->count());
    }

    public function test_nothing_can_be_adjusted_before_a_rule_exists(): void
    {
        $this->accrue(400);

        $this->adjustAs($this->owner, [
            'amount' => 100,
            'reason' => 'There is no rule to measure this against yet.',
        ])->assertStatus(409);

        $this->assertSame(400.0, $this->ledgerSum());
    }

    public function test_an_adjustment_counts_towards_eligibility(): void
    {
        // Threshold 1,000. The adjustment is what carries them over it, which is the
        // whole point of being able to make one.
        $this->publishRule();
        $this->accrue(900);

        $this->adjustAs($this->owner, [
            'amount' => 200,
            'reason' => 'Goodwill after a delivery went wrong.',
        ])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}/redemptions/preview")
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('cycle.total_amount', '1100.00');
    }

    public function test_the_history_lists_past_adjustments_with_their_reasons(): void
    {
        $this->publishRule();
        $this->accrue(400);

        $this->adjustAs($this->owner, [
            'amount' => 50,
            'reason' => 'Migrated from the old paper card.',
        ])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}/adjustments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '50.00')
            ->assertJsonPath('data.0.note', 'Migrated from the old paper card.')
            ->assertJsonPath('data.0.created_by', $this->owner->name);
    }

    public function test_an_owner_cannot_adjust_another_stores_customer(): void
    {
        $this->publishRule();

        $other = Merchant::factory()->create();

        $theirCustomer = app(TenantContext::class)->for($other->id, fn () => Customer::create([
            'phone' => '0980000000',
            'name' => 'Their Customer',
        ]));

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/customers/{$theirCustomer->id}/adjustments", [
                'branch_id' => $this->branch->id,
                'amount' => 500,
                'reason' => 'Trying to reach into another store.',
            ])
            ->assertNotFound();

        $this->assertSame(0, LedgerEntry::withoutGlobalScopes()->count());
    }
}
