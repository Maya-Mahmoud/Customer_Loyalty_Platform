<?php

namespace Tests\Feature;

use App\Enums\ConsentStatus;
use App\Enums\LedgerEntryType;
use App\Enums\RewardType;
use App\Enums\VoucherStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
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
 * Erasing a customer at their request (BRD FR-CUS-10, section 16).
 *
 * Every case here is about the line between two duties: the customer's right to have
 * their personal data removed, and the merchant's duty to keep a record of sales that
 * actually happened. So the tests check both halves — that the identity is gone, and
 * that the invoices, the entries and the rewards are untouched.
 */
class CustomerErasureTest extends TestCase
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
            'name' => 'Sami Al-Halabi',
            'consent_status' => ConsentStatus::Granted,
            'registered_at_branch_id' => $this->branch->id,
        ]);

        app(TenantContext::class)->forget();
    }

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

    private function sale(float $amount, string $number = 'INV-1'): Invoice
    {
        $invoice = Invoice::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->rep->id,
            'customer_id' => $this->customer->id,
            'invoice_number' => $number,
            'amount' => $amount,
            'invoice_date' => now()->toDateString(),
        ]);

        LedgerEntry::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'cycle_number' => $this->customer->fresh()->current_cycle_number,
            'type' => LedgerEntryType::Accrual,
            'amount' => $amount,
            'invoice_count_delta' => 1,
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
        ]);

        return $invoice;
    }

    private function erase(User $user, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/customers/{$this->customer->id}/anonymize", [
                'reason' => 'The customer asked in writing for their data to be removed.',
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
    // Both halves of the obligation
    // -----------------------------------------------------------------

    public function test_the_identity_is_removed_and_the_sales_are_not(): void
    {
        $this->publishRule();
        $invoice = $this->sale(600);

        $this->erase($this->owner)->assertOk();

        $fresh = Customer::withoutGlobalScopes()->find($this->customer->id);

        // Gone: everything that points at a person.
        $this->assertNull($fresh->name);
        $this->assertStringNotContainsString('0991234567', $fresh->phone);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertSame(ConsentStatus::Withdrawn, $fresh->consent_status);
        $this->assertFalse($fresh->is_active);

        // Kept: the sale happened, and an invoice cannot be unmade because the buyer
        // later asked.
        $stored = Invoice::withoutGlobalScopes()->find($invoice->id);
        $this->assertSame('600.00', $stored->amount);
        $this->assertSame($this->customer->id, $stored->customer_id);
        $this->assertSame(
            1,
            LedgerEntry::withoutGlobalScopes()->where('type', LedgerEntryType::Accrual)->count()
        );
    }

    public function test_an_open_balance_is_closed_with_an_entry_of_its_own(): void
    {
        $this->publishRule();
        $this->sale(600);

        $this->erase($this->owner)
            ->assertOk()
            ->assertJsonPath('balance_written_off', 600);

        /*
         * A balance left behind would belong to somebody who no longer exists in the
         * system: unclaimable by them, unexplainable to an auditor.
         */
        $entry = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::Expiry)->sole();
        $this->assertSame('-600.00', $entry->amount);
        $this->assertSame(-1, $entry->invoice_count_delta);
        $this->assertNotNull($entry->note);

        // The invariant of BRD 20 still holds: the entries sum to the balance.
        $this->assertSame(0.0, $this->ledgerSum());
    }

    public function test_a_customer_with_nothing_accumulated_needs_no_entry(): void
    {
        $this->publishRule();

        $this->erase($this->owner)
            ->assertOk()
            ->assertJsonPath('balance_written_off', 0);

        // An entry worth zero would still be a movement.
        $this->assertSame(0, LedgerEntry::withoutGlobalScopes()->count());
    }

    public function test_live_vouchers_are_withdrawn_and_spent_ones_are_left_alone(): void
    {
        $rule = $this->publishRule(['reward_type' => RewardType::Voucher, 'reward_value' => 50]);

        $redemption = Redemption::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'loyalty_rule_id' => $rule->id,
            'cycle_number' => 1,
            'cycle_total_amount' => 1000,
            'cycle_invoice_count' => 1,
            'reward_type' => RewardType::Voucher,
            'computed_amount' => 50,
            'discount_amount' => 50,
            'carried_over_amount' => 0,
            'performed_by' => $this->manager->id,
            'redeemed_at' => now(),
        ]);

        $live = Voucher::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'redemption_id' => $redemption->id,
            'code' => 'LIVE01',
            'amount' => 50,
            'issued_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        $spent = Voucher::withoutGlobalScopes()->create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'redemption_id' => $redemption->id,
            'code' => 'SPENT1',
            'amount' => 30,
            'status' => VoucherStatus::Used,
            'issued_at' => now()->subDays(5),
            'expires_at' => now()->addDays(25),
            'used_at' => now()->subDay(),
        ]);

        $this->erase($this->owner)
            ->assertOk()
            ->assertJsonPath('vouchers_cancelled', 1);

        $this->assertSame(VoucherStatus::Cancelled, $live->fresh()->status);
        // Already honoured, and the sale it paid for is part of the record.
        $this->assertSame(VoucherStatus::Used, $spent->fresh()->status);

        // The reward itself stands: it was paid, and that is a fact about the past.
        $this->assertSame(1, Redemption::withoutGlobalScopes()->count());
    }

    // -----------------------------------------------------------------
    // What it must not leave behind
    // -----------------------------------------------------------------

    public function test_the_old_number_no_longer_finds_anyone(): void
    {
        $this->publishRule();
        $this->erase($this->owner)->assertOk();

        $this->actingAs($this->rep, 'sanctum')
            ->getJson('/api/v1/customers/lookup?phone=0991234567')
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_the_audit_entry_records_the_act_and_not_the_person(): void
    {
        $this->publishRule();
        $this->sale(600);

        $this->erase($this->owner)->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'customer.anonymized')->sole();

        /*
         * Writing the old number into the log would move the personal data from one
         * table to another and leave the request unfulfilled.
         */
        $encoded = json_encode($log->after, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('0991234567', $encoded);
        $this->assertStringNotContainsString('Sami', $encoded);

        $this->assertSame($this->customer->id, $log->after['customer_id']);
        $this->assertEquals(600, $log->after['balance_written_off']);
        $this->assertSame($this->owner->id, $log->user_id);
    }

    public function test_an_erased_customer_is_left_out_of_the_export(): void
    {
        $this->publishRule();
        $this->erase($this->owner)->assertOk();

        $csv = $this->actingAs($this->owner, 'sanctum')
            ->get('/api/v1/customers/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('Sami', $csv);
        $this->assertStringNotContainsString('0991234567', $csv);
    }

    // -----------------------------------------------------------------
    // Who may do it, and how often
    // -----------------------------------------------------------------

    public function test_only_the_owner_can_erase_a_customer(): void
    {
        $this->publishRule();

        // A data subject request is answered by the business, not at a till.
        $this->erase($this->rep)->assertForbidden();
        $this->erase($this->manager)->assertForbidden();

        $this->assertNull(Customer::withoutGlobalScopes()->find($this->customer->id)->anonymized_at);
    }

    public function test_a_written_reason_is_required(): void
    {
        $this->publishRule();

        $this->erase($this->owner, ['reason' => 'طلب'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertNull(Customer::withoutGlobalScopes()->find($this->customer->id)->anonymized_at);
    }

    public function test_it_cannot_be_done_twice(): void
    {
        $this->publishRule();
        $this->sale(600);

        $this->erase($this->owner)->assertOk();
        $this->erase($this->owner)->assertStatus(409);

        // One write-off, not two.
        $this->assertSame(
            1,
            LedgerEntry::withoutGlobalScopes()->where('type', LedgerEntryType::Expiry)->count()
        );
    }

    public function test_an_owner_cannot_erase_another_stores_customer(): void
    {
        $other = Merchant::factory()->create();

        $theirs = app(TenantContext::class)->for($other->id, fn () => Customer::create([
            'phone' => '0980000000',
            'name' => 'Their Customer',
        ]));

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/customers/{$theirs->id}/anonymize", [
                'reason' => 'Trying to reach into another store.',
            ])
            ->assertNotFound();

        $this->assertNull(Customer::withoutGlobalScopes()->find($theirs->id)->anonymized_at);
    }
}
