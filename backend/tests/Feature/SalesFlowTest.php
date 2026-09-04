<?php

namespace Tests\Feature;

use App\Enums\ConsentStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Recording a sale and looking a customer up (BRD 8.4, 8.5).
 *
 * This is the flow the rest of the system exists to serve, so the cases here are
 * mostly about what happens when it goes sideways: a duplicate number, a customer
 * who refuses to identify themselves, a sale too small to count, a rule that does
 * not exist yet.
 */
class SalesFlowTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->merchant = Merchant::factory()->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();
        $this->rep = User::factory()->salesRep($this->branch)->create(['phone' => '0930000001']);
    }

    private function asRep(): self
    {
        $this->actingAs($this->rep, 'sanctum');

        return $this;
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

    /** Registers through the API, the way a rep would. */
    private function registerCustomer(string $phone = '0991234567', string $name = 'Sami'): int
    {
        return $this->asRep()->postJson('/api/v1/customers', [
            'phone' => $phone,
            'name' => $name,
            'consent_given' => true,
        ])->assertCreated()->json('customer.id');
    }

    private function recordSale(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->asRep()->postJson('/api/v1/invoices', [
            'invoice_number' => 'INV-1',
            'amount' => 500,
            'invoice_date' => now()->toDateString(),
            ...$payload,
        ]);
    }

    // -----------------------------------------------------------------
    // Registering a customer (FR-CUS-01, BR-011)
    // -----------------------------------------------------------------

    public function test_a_rep_registers_a_customer_with_nothing_but_a_name_and_a_number(): void
    {
        $this->publishRule();

        $response = $this->asRep()->postJson('/api/v1/customers', [
            'phone' => '0991 234 567',
            'name' => 'Sami Haddad',
            'consent_given' => true,
        ])->assertCreated();

        // BRD BR-002: normalised, because spacing must not create two records.
        $this->assertSame('0991234567', $response->json('customer.phone'));

        $customer = Customer::findOrFail($response->json('customer.id'));

        $this->assertSame(ConsentStatus::Granted, $customer->consent_status);
        $this->assertNotNull($customer->consent_recorded_at);
        // Attribution is what makes the collusion controls of AF-03 possible.
        $this->assertSame($this->rep->id, $customer->registered_by_user_id);
        $this->assertSame($this->branch->id, $customer->registered_at_branch_id);
        $this->assertSame(1, $customer->current_cycle_number);
    }

    public function test_a_customer_never_gets_an_account(): void
    {
        $this->registerCustomer();

        // BRD BR-001: the customer is not a user of the system at any point.
        $this->assertDatabaseMissing('users', ['phone' => '0991234567']);
    }

    public function test_a_staff_number_cannot_be_registered_as_a_customer(): void
    {
        /*
         * BRD AF-04. Section 12.2 calls this the cheapest and most effective
         * control: without it a rep can accumulate walk-in purchases onto a number
         * they control and collect the rewards themselves.
         */
        $this->asRep()->postJson('/api/v1/customers', [
            'phone' => $this->rep->phone,
            'name' => 'Definitely Not Me',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_the_same_number_cannot_be_registered_twice(): void
    {
        $this->registerCustomer();

        $this->asRep()->postJson('/api/v1/customers', [
            'phone' => '0991234567',
            'name' => 'Someone Else',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_the_same_number_at_another_merchant_is_a_separate_record(): void
    {
        // Built before any request: once one has run, the tenant context is pinned
        // to this merchant and writing another's rows is rightly refused.
        $other = Merchant::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $otherRep = User::factory()->salesRep($otherBranch)->create();

        $this->registerCustomer();

        // BRD BR-002: the same person may hold unrelated balances at several
        // stores, with no link between them.
        $this->actingAs($otherRep, 'sanctum')->postJson('/api/v1/customers', [
            'phone' => '0991234567',
            'name' => 'Their Customer',
        ])->assertCreated();

        $this->assertSame(2, Customer::withoutGlobalScopes()->where('phone', '0991234567')->count());
    }

    // -----------------------------------------------------------------
    // Lookup (FR-CUS-04, FR-CUS-05)
    // -----------------------------------------------------------------

    public function test_an_unknown_number_answers_plainly_rather_than_failing(): void
    {
        // Not finding a number is how the rep learns to offer registration, so it
        // is a normal outcome and not an error.
        $this->asRep()->getJson('/api/v1/customers/lookup?phone=0999999999')
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('customer', null);
    }

    public function test_the_card_shows_everything_the_rep_needs_at_the_counter(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();

        $this->recordSale(['customer_id' => $customerId, 'amount' => 400])->assertCreated();

        $response = $this->asRep()->getJson('/api/v1/customers/lookup?phone=0991234567')->assertOk();

        // BRD FR-CUS-05, all from the ledger rather than a stored balance.
        $response->assertJsonPath('found', true)
            ->assertJsonPath('customer.name', 'Sami')
            ->assertJsonPath('customer.cycle.total_amount', 400)
            ->assertJsonPath('customer.cycle.invoice_count', 1)
            ->assertJsonPath('customer.cycle.amount_remaining', 600)
            ->assertJsonPath('customer.cycle.is_eligible', false)
            ->assertJsonPath('customer.redemptions_count', 0);

        // BRD FR-CUS-06: the progress bar.
        $this->assertSame(0.4, $response->json('customer.cycle.progress'));
    }

    public function test_the_card_works_before_any_rule_is_published(): void
    {
        $customerId = $this->registerCustomer();

        $this->recordSale(['customer_id' => $customerId])->assertCreated();

        // Nothing to accumulate towards, but the rep can still record and look up.
        $this->asRep()->getJson('/api/v1/customers/lookup?phone=0991234567')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('customer.cycle', null);
    }

    public function test_the_history_screen_lists_past_invoices(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();

        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => 'INV-1', 'amount' => 300])->assertCreated();
        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => 'INV-2', 'amount' => 5])->assertCreated();

        $response = $this->asRep()->getJson("/api/v1/customers/{$customerId}")->assertOk();

        $this->assertCount(2, $response->json('customer.invoices'));

        // The one under the minimum is listed, and visibly did not count — the rep
        // can explain it later without guessing.
        $small = collect($response->json('customer.invoices'))->firstWhere('invoice_number', 'INV-2');
        $this->assertFalse($small['qualifies_for_accumulation']);
    }

    // -----------------------------------------------------------------
    // Recording a sale (FR-INV-01 to FR-INV-03)
    // -----------------------------------------------------------------

    public function test_a_sale_creates_one_ledger_entry_and_no_stored_balance(): void
    {
        $rule = $this->publishRule();
        $customerId = $this->registerCustomer();

        $this->recordSale(['customer_id' => $customerId, 'amount' => 500])
            ->assertCreated()
            ->assertJsonPath('counted', true)
            ->assertJsonPath('cycle.total_amount', 500)
            ->assertJsonPath('cycle.amount_remaining', 500);

        $entries = LedgerEntry::where('customer_id', $customerId)->get();

        // BRD 13.3 and BR-008: the balance is the sum of entries, never a column.
        $this->assertCount(1, $entries);
        $this->assertSame('500.00', $entries->first()->amount);
        $this->assertSame(1, $entries->first()->invoice_count_delta);
        $this->assertSame($rule->id, $entries->first()->loyalty_rule_id);
        // Traceable back to the sale that caused it.
        $this->assertSame(Invoice::class, $entries->first()->source_type);
    }

    public function test_the_entry_records_the_branch_and_the_user_who_keyed_it_in(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();

        $this->recordSale(['customer_id' => $customerId])->assertCreated();

        // BRD FR-INV-03, and what the AF-03 and AF-10 reviews depend on.
        $invoice = Invoice::firstOrFail();
        $this->assertSame($this->branch->id, $invoice->branch_id);
        $this->assertSame($this->rep->id, $invoice->user_id);
    }

    public function test_the_branch_comes_from_the_account_not_the_request(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();
        $elsewhere = Branch::factory()->for($this->merchant)->create(['name' => 'Elsewhere']);

        // A rep works at their own branch; claiming another would misattribute the
        // sale and corrupt the branch performance report of RPT-03.
        $this->recordSale(['customer_id' => $customerId, 'branch_id' => $elsewhere->id])->assertCreated();

        $this->assertSame($this->branch->id, Invoice::firstOrFail()->branch_id);
    }

    public function test_an_owner_must_say_which_branch(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();
        $owner = User::factory()->owner($this->merchant->id)->create();

        // An owner spans every branch, so there is nothing to infer.
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/invoices', [
            'invoice_number' => 'INV-OWNER',
            'amount' => 100,
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customerId,
        ])->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_eligibility_is_reported_the_moment_it_is_reached(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();

        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => 'INV-1', 'amount' => 600])
            ->assertCreated()
            ->assertJsonPath('cycle.is_eligible', false);

        // BRD FR-RED-01 and FR-RED-02: told at the counter, while the customer is
        // still standing there.
        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => 'INV-2', 'amount' => 500])
            ->assertCreated()
            ->assertJsonPath('cycle.is_eligible', true)
            ->assertJsonPath('cycle.amount_remaining', 0)
            ->assertJsonPath('cycle.progress', 1);
    }

    // -----------------------------------------------------------------
    // The refusals
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // The number on the receipt (FR-INV-01, and the proof behind FR-CUS-12)
    // -----------------------------------------------------------------

    public function test_a_sale_cannot_be_recorded_without_an_invoice_number(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();

        /*
         * Required, and deliberately not something the system can invent. The number
         * is what a customer quotes back to prove the card is theirs (FR-CUS-12), and
         * one this application made up for itself would prove nothing to anybody.
         */
        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');

        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');
    }

    public function test_a_duplicate_invoice_number_is_refused(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();

        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => 'INV-77'])->assertCreated();

        // BRD BR-004 and AF-01: entering the same sale twice would inflate the
        // balance towards a reward that was never earned.
        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => 'INV-77'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_number');

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_the_same_invoice_number_is_free_at_another_merchant(): void
    {
        // Built before any request, for the same reason as above.
        $other = Merchant::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $otherRep = User::factory()->salesRep($otherBranch)->create();

        $this->publishRule();
        $customerId = $this->registerCustomer();
        $this->recordSale(['customer_id' => $customerId, 'invoice_number' => 'INV-77'])->assertCreated();

        // BRD BR-004: unique inside one merchant, not across the platform — each
        // store issues its own numbering.
        $this->actingAs($otherRep, 'sanctum')->postJson('/api/v1/invoices', [
            'invoice_number' => 'INV-77',
            'amount' => 100,
            'invoice_date' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_a_sale_below_the_minimum_is_recorded_but_not_counted(): void
    {
        $this->publishRule(['min_invoice_amount' => 10]);
        $customerId = $this->registerCustomer();

        // BRD BR-003: this is what stops a sale being split into fragments to
        // manufacture visits.
        $this->recordSale(['customer_id' => $customerId, 'amount' => 8])
            ->assertCreated()
            ->assertJsonPath('counted', false)
            ->assertJsonPath('data.qualifies_for_accumulation', false)
            ->assertJsonPath('cycle.total_amount', 0);

        $this->assertSame(1, Invoice::count());
        // No entry at all: the ledger stays a record of movements only.
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_customer_who_refuses_to_identify_still_gets_a_recorded_sale(): void
    {
        $this->publishRule();

        // BRD BR-022: respects the refusal while keeping the sales reports accurate.
        $this->recordSale(['customer_id' => null, 'amount' => 900])
            ->assertCreated()
            ->assertJsonPath('counted', false)
            ->assertJsonPath('cycle', null);

        $this->assertSame(1, Invoice::count());
        $this->assertNull(Invoice::firstOrFail()->customer_id);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_sale_cannot_be_dated_in_the_future(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();

        $this->recordSale([
            'customer_id' => $customerId,
            'invoice_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_date');
    }

    public function test_a_customer_of_another_merchant_cannot_be_attached(): void
    {
        $this->publishRule();

        $other = Merchant::factory()->create();
        $foreign = Customer::withoutGlobalScopes()->create([
            'merchant_id' => $other->id,
            'phone' => '0977777777',
            'name' => 'Their Customer',
        ]);

        $this->recordSale(['customer_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    // -----------------------------------------------------------------
    // Backdating and rule versions (BR-015)
    // -----------------------------------------------------------------

    public function test_a_backdated_sale_uses_the_rule_that_applied_then(): void
    {
        // Old rule: threshold 1,000, minimum 10. New rule from today: minimum 100.
        $old = $this->publishRule([
            'version' => 1,
            'min_invoice_amount' => 10,
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => now()->subDay()->toDateString(),
            'is_active' => false,
        ]);

        $this->publishRule([
            'version' => 2,
            'min_invoice_amount' => 100,
            'effective_from' => now()->toDateString(),
        ]);

        $customerId = $this->registerCustomer();

        // A sale of 50 from last week counted then, and must still count now.
        $this->recordSale([
            'customer_id' => $customerId,
            'amount' => 50,
            'invoice_date' => now()->subWeek()->toDateString(),
            'invoice_number' => 'INV-OLD',
        ])->assertCreated()->assertJsonPath('counted', true);

        $this->assertSame($old->id, LedgerEntry::firstOrFail()->loyalty_rule_id);

        // The same amount today falls under the new minimum.
        $this->recordSale([
            'customer_id' => $customerId,
            'amount' => 50,
            'invoice_number' => 'INV-NEW',
        ])->assertCreated()->assertJsonPath('counted', false);
    }

    // -----------------------------------------------------------------
    // Access (BRD 7.2)
    // -----------------------------------------------------------------

    public function test_a_rep_may_record_and_look_up_but_not_manage(): void
    {
        // BRD 7.2 gives a rep exactly these three, and nothing else.
        $this->asRep()->getJson('/api/v1/customers/lookup?phone=0991234567')->assertOk();
        $this->asRep()->getJson('/api/v1/branches')->assertStatus(403);
        $this->asRep()->getJson('/api/v1/staff')->assertStatus(403);
        $this->asRep()->getJson('/api/v1/loyalty-rule')->assertStatus(403);
    }

    public function test_the_platform_supervisor_has_no_place_at_the_till(): void
    {
        // BRD 7.1: the supervisor runs the platform and has no business in a
        // merchant's commercial data.
        $supervisor = User::factory()->platformAdmin()->create();

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/customers/lookup?phone=0991234567')
            ->assertStatus(403);

        $this->actingAs($supervisor, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'invoice_number' => 'INV-X',
                'amount' => 100,
                'invoice_date' => now()->toDateString(),
            ])->assertStatus(403);
    }

    public function test_a_customer_of_another_merchant_is_invisible(): void
    {
        $other = Merchant::factory()->create();
        $foreign = Customer::withoutGlobalScopes()->create([
            'merchant_id' => $other->id,
            'phone' => '0966666666',
            'name' => 'Theirs',
        ]);

        $this->asRep()->getJson('/api/v1/customers/lookup?phone=0966666666')
            ->assertOk()
            ->assertJsonPath('found', false);

        $this->asRep()->getJson("/api/v1/customers/{$foreign->id}")->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Consent (section 16)
    // -----------------------------------------------------------------

    public function test_consent_can_be_withdrawn_on_the_customers_behalf(): void
    {
        $customerId = $this->registerCustomer();

        // Section 16 requires consent to be withdrawable at any time, and the
        // customer has no account to do it from.
        $this->asRep()->putJson("/api/v1/customers/{$customerId}/consent", ['consent_given' => false])
            ->assertOk()
            ->assertJsonPath('customer.consent_status', 'withdrawn');
    }

    public function test_registering_without_consent_records_that_honestly(): void
    {
        $response = $this->asRep()->postJson('/api/v1/customers', [
            'phone' => '0995555555',
            'name' => 'Quiet Customer',
            'consent_given' => false,
        ])->assertCreated();

        // Not the same as granted-then-withdrawn: it was never given.
        $this->assertSame('not_collected', $response->json('customer.consent_status'));
    }

    // -----------------------------------------------------------------
    // Audit
    // -----------------------------------------------------------------

    public function test_registration_and_sales_are_written_to_the_audit_log(): void
    {
        $this->publishRule();
        $customerId = $this->registerCustomer();
        $this->recordSale(['customer_id' => $customerId])->assertCreated();

        $this->assertSame(1, AuditLog::where('action', 'customer.registered')->count());

        $entry = AuditLog::where('action', 'invoice.recorded')->firstOrFail();
        $this->assertSame($this->rep->id, $entry->user_id);
        $this->assertSame('INV-1', $entry->after['invoice_number']);
    }
}
