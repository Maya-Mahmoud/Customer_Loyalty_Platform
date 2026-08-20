<?php

namespace Tests\Feature;

use App\Enums\CorrectionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceCorrection;
use App\Models\LedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelling and returning invoices (BRD 8.7).
 *
 * Three rules carry most of the weight. BR-010: the invoice survives as a cancelled
 * record, never a deleted one. BR-009: the accumulation is undone by a reversing
 * entry, so the ledger stays append-only. BR-012: the person who raises a request is
 * not the person who decides it, unless they already hold that authority.
 *
 * The last case is the open question of OD-06 — a cancellation arriving after the
 * cycle was already paid out — and it is tested here so the answer is written down
 * somewhere executable rather than only in a decision log.
 */
class CorrectionTest extends TestCase
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

    /** Recorded through the API, so the accrual entry is written the real way. */
    private function recordSale(float $amount, string $number = 'INV-1'): Invoice
    {
        $id = $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'invoice_number' => $number,
                'amount' => $amount,
                'invoice_date' => now()->toDateString(),
                'customer_id' => $this->customer->id,
            ])
            ->assertCreated()
            ->json('data.id');

        return Invoice::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestCorrection(User $user, Invoice $invoice, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/corrections", [
                'type' => 'cancel',
                'reason' => 'The customer returned the goods the same day.',
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
    // Request and approve (BR-012, FR-INV-08)
    // -----------------------------------------------------------------

    public function test_a_reps_request_waits_for_a_decision_and_changes_nothing_yet(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $this->requestCorrection($this->rep, $invoice)
            ->assertCreated()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('data.status', 'pending');

        // BR-012: nothing moves on a request alone.
        $this->assertSame(500.0, $this->ledgerSum());
        $this->assertSame(
            InvoiceStatus::Active,
            Invoice::withoutGlobalScopes()->find($invoice->id)->status
        );
    }

    public function test_the_manager_approving_reverses_the_accumulation_and_cancels_the_invoice(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $correctionId = $this->requestCorrection($this->rep, $invoice)->json('data.id');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/corrections/{$correctionId}/approve", [
                'review_note' => 'Checked with the branch.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // BR-009: a reversing entry, not an edited one. The accrual is untouched.
        $reversal = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::Reversal)->sole();
        $this->assertSame('-500.00', $reversal->amount);
        $this->assertSame(-1, $reversal->invoice_count_delta);
        $this->assertSame(1, $reversal->cycle_number);

        $this->assertSame(0.0, $this->ledgerSum());

        // BR-010: cancelled, never deleted.
        $stored = Invoice::withoutGlobalScopes()->find($invoice->id);
        $this->assertSame(InvoiceStatus::Cancelled, $stored->status);
        $this->assertNotNull($stored->cancelled_at);
        $this->assertSame('500.00', $stored->amount);

        $this->assertTrue(
            AuditLog::withoutGlobalScopes()->where('action', 'correction.applied')->exists()
        );
    }

    public function test_a_managers_own_request_is_applied_at_once(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $this->requestCorrection($this->manager, $invoice)
            ->assertCreated()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('data.status', 'approved');

        // They already hold the authority, so waiting for their own approval would
        // be theatre.
        $this->assertSame(0.0, $this->ledgerSum());
    }

    public function test_a_rejected_request_leaves_the_invoice_and_the_balance_alone(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $correctionId = $this->requestCorrection($this->rep, $invoice)->json('data.id');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/corrections/{$correctionId}/reject", [
                'review_note' => 'The goods were not returned.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(500.0, $this->ledgerSum());
        $this->assertSame(
            InvoiceStatus::Active,
            Invoice::withoutGlobalScopes()->find($invoice->id)->status
        );

        // The refused request stays on the record: a rejection is evidence too.
        $this->assertSame(1, InvoiceCorrection::withoutGlobalScopes()->count());
    }

    public function test_a_rep_cannot_decide_on_a_request(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $correctionId = $this->requestCorrection($this->rep, $invoice)->json('data.id');

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/v1/corrections/{$correctionId}/approve")
            ->assertForbidden();

        $this->actingAs($this->rep, 'sanctum')
            ->getJson('/api/v1/corrections')
            ->assertForbidden();

        $this->assertSame(500.0, $this->ledgerSum());
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $correctionId = $this->requestCorrection($this->rep, $invoice)->json('data.id');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/corrections/{$correctionId}/approve")
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/corrections/{$correctionId}/approve")
            ->assertStatus(409);

        // One approval, one reversal.
        $this->assertSame(
            1,
            LedgerEntry::withoutGlobalScopes()->where('type', LedgerEntryType::Reversal)->count()
        );
    }

    public function test_a_second_request_is_refused_while_one_is_pending(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $this->requestCorrection($this->rep, $invoice)->assertCreated();
        $this->requestCorrection($this->rep, $invoice)->assertStatus(409);

        $this->assertSame(1, InvoiceCorrection::withoutGlobalScopes()->count());
    }

    public function test_a_cancelled_invoice_cannot_be_cancelled_twice(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $this->requestCorrection($this->manager, $invoice)->assertCreated();
        $this->requestCorrection($this->manager, $invoice)->assertStatus(409);

        $this->assertSame(0.0, $this->ledgerSum());
    }

    public function test_a_reason_is_required_and_has_to_say_something(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $this->requestCorrection($this->rep, $invoice, ['reason' => 'خطأ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(0, InvoiceCorrection::withoutGlobalScopes()->count());
    }

    // -----------------------------------------------------------------
    // Returns (FR-INV-07)
    // -----------------------------------------------------------------

    public function test_a_partial_return_takes_back_the_money_but_not_the_visit(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $this->requestCorrection($this->manager, $invoice, [
            'type' => 'partial_return',
            'amount' => 200,
            'reason' => 'One of the two items was returned.',
        ])->assertCreated();

        $reversal = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::Reversal)->sole();
        $this->assertSame('-200.00', $reversal->amount);
        // The customer did come in and did buy, so the visit still counts.
        $this->assertSame(0, $reversal->invoice_count_delta);

        $this->assertSame(300.0, $this->ledgerSum());

        // The invoice stands: part of the sale is still real.
        $stored = Invoice::withoutGlobalScopes()->find($invoice->id);
        $this->assertSame(InvoiceStatus::Active, $stored->status);
        $this->assertSame('500.00', $stored->amount);
    }

    public function test_a_partial_return_cannot_reach_the_whole_invoice(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $this->requestCorrection($this->manager, $invoice, [
            'type' => 'partial_return',
            'amount' => 500,
            'reason' => 'Everything came back to the counter.',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(500.0, $this->ledgerSum());
    }

    public function test_a_cancellation_after_a_partial_return_reverses_only_what_is_left(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(500);

        $this->requestCorrection($this->manager, $invoice, [
            'type' => 'partial_return',
            'amount' => 200,
            'reason' => 'One of the two items was returned.',
        ])->assertCreated();

        $this->requestCorrection($this->manager, $invoice, [
            'type' => 'full_return',
            'reason' => 'The customer brought the rest back the next day.',
        ])->assertCreated();

        // 500 accrued, 200 then 300 reversed — never 200 then 500.
        $this->assertSame(0.0, $this->ledgerSum());
        $this->assertSame(
            2,
            LedgerEntry::withoutGlobalScopes()->where('type', LedgerEntryType::Reversal)->count()
        );
    }

    public function test_cancelling_an_invoice_that_never_counted_writes_no_entry(): void
    {
        // Under the rule minimum (BR-003), so it never accumulated anything.
        $this->publishRule(['min_invoice_amount' => 100]);
        $invoice = $this->recordSale(50);

        $this->assertSame(0, LedgerEntry::withoutGlobalScopes()->count());

        $this->requestCorrection($this->manager, $invoice)->assertCreated();

        // The sale is cancelled, and the ledger stays a record of movements only.
        $this->assertSame(0, LedgerEntry::withoutGlobalScopes()->count());
        $this->assertSame(
            InvoiceStatus::Cancelled,
            Invoice::withoutGlobalScopes()->find($invoice->id)->status
        );
    }

    // -----------------------------------------------------------------
    // The open question of OD-06
    // -----------------------------------------------------------------

    public function test_a_cancellation_after_the_reward_was_paid_hits_the_open_cycle_instead(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(1200);

        // The reward is paid, which closes cycle 1 and opens cycle 2.
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/customers/{$this->customer->id}/redemptions")
            ->assertCreated();

        $this->assertSame(2, $this->customer->fresh()->current_cycle_number);

        $this->requestCorrection($this->manager, $invoice)->assertCreated();

        /*
         * The decision: the discount has been consumed and is not clawed back, so
         * cycle 1 stays settled and the reversal lands on the cycle that is open
         * now. The customer's new cycle goes negative until they buy again.
         */
        $reversal = LedgerEntry::withoutGlobalScopes()
            ->where('type', LedgerEntryType::Reversal)->sole();
        $this->assertSame(2, $reversal->cycle_number);
        $this->assertSame('-1200.00', $reversal->amount);

        // The redemption itself is untouched.
        $this->assertSame(1, Redemption::withoutGlobalScopes()->count());
        $this->assertSame(
            CorrectionStatus::Approved,
            InvoiceCorrection::withoutGlobalScopes()->sole()->status
        );

        // 1200 accrued, -1200 closing, +200 carried, -1200 reversed. The 200 the
        // customer had kept goes with the sale it came from, leaving them 1,000
        // short — exactly the value of the discount they were paid and kept.
        $this->assertSame(-1000.0, $this->ledgerSum());

        // And it is findable afterwards, which is the point of recording it.
        $log = AuditLog::withoutGlobalScopes()->where('action', 'correction.applied')->sole();
        $this->assertTrue($log->after['after_redemption']);
    }

    public function test_a_negative_cycle_shows_as_no_progress_rather_than_a_negative_bar(): void
    {
        $this->publishRule();
        $invoice = $this->recordSale(1200);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/customers/{$this->customer->id}/redemptions")
            ->assertCreated();

        $this->requestCorrection($this->manager, $invoice)->assertCreated();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('customer.cycle.progress', 0)
            ->assertJsonPath('customer.cycle.is_eligible', false);
    }

    // -----------------------------------------------------------------
    // What the card shows
    // -----------------------------------------------------------------

    public function test_the_customer_card_shows_a_pending_request_and_what_came_back(): void
    {
        $this->publishRule();
        $pending = $this->recordSale(500, 'INV-PENDING');
        $returned = $this->recordSale(400, 'INV-RETURNED');

        $this->requestCorrection($this->rep, $pending)->assertCreated();

        $this->requestCorrection($this->manager, $returned, [
            'type' => 'partial_return',
            'amount' => 150,
            'reason' => 'One item out of three was returned.',
        ])->assertCreated();

        $invoices = $this->actingAs($this->rep, 'sanctum')
            ->getJson("/api/v1/customers/{$this->customer->id}")
            ->assertOk()
            ->json('customer.invoices');

        $byNumber = collect($invoices)->keyBy('invoice_number');

        // A second request on the same invoice would only be refused, so the till
        // is told there is one open (BRD 8.7).
        $this->assertTrue($byNumber['INV-PENDING']['pending_correction']);
        $this->assertSame('0.00', $byNumber['INV-PENDING']['returned_amount']);

        $this->assertFalse($byNumber['INV-RETURNED']['pending_correction']);
        $this->assertSame('150.00', $byNumber['INV-RETURNED']['returned_amount']);
        // BR-010: the invoice still carries the amount that was keyed in.
        $this->assertSame('400.00', $byNumber['INV-RETURNED']['amount']);
    }

    // -----------------------------------------------------------------
    // The queue, and tenancy
    // -----------------------------------------------------------------

    public function test_the_pending_queue_shows_a_manager_only_their_own_branch(): void
    {
        $this->publishRule();
        $mine = $this->recordSale(500, 'INV-MINE');

        $otherBranch = Branch::factory()->for($this->merchant)->create();
        $otherRep = User::factory()->salesRep($otherBranch)->create();

        $theirs = $this->actingAs($otherRep, 'sanctum')
            ->postJson('/api/v1/invoices', [
                'invoice_number' => 'INV-THEIRS',
                'amount' => 300,
                'invoice_date' => now()->toDateString(),
                'customer_id' => $this->customer->id,
            ])->assertCreated()->json('data.id');

        $this->requestCorrection($this->rep, $mine)->assertCreated();

        $this->actingAs($otherRep, 'sanctum')
            ->postJson("/api/v1/invoices/{$theirs}/corrections", [
                'type' => 'cancel',
                'reason' => 'Entered against the wrong customer.',
            ])->assertCreated();

        // FR-BRN-03 applied to the queue: a branch manager decides on their branch.
        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/corrections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice.invoice_number', 'INV-MINE');

        // The owner spans the store, so they see both.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/corrections')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_manager_cannot_touch_another_stores_invoice(): void
    {
        $other = Merchant::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();

        $otherInvoice = app(TenantContext::class)->for($other->id, fn () => Invoice::create([
            'branch_id' => $otherBranch->id,
            'user_id' => User::factory()->owner($other->id)->create()->id,
            'customer_id' => null,
            'invoice_number' => 'INV-OTHER',
            'amount' => 900,
            'invoice_date' => now()->toDateString(),
        ]));

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/invoices/{$otherInvoice->id}/corrections", [
                'type' => 'cancel',
                'reason' => 'Trying to reach into another store.',
            ])
            ->assertNotFound();

        $this->assertSame(0, InvoiceCorrection::withoutGlobalScopes()->count());
        $this->assertSame(
            InvoiceStatus::Active,
            Invoice::withoutGlobalScopes()->find($otherInvoice->id)->status
        );
    }
}
