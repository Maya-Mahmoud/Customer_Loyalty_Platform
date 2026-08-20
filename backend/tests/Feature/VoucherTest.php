<?php

namespace Tests\Feature;

use App\Enums\RewardType;
use App\Enums\VoucherStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Loyalty\VoucherService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Purchase vouchers — spending credit issued as a reward and redeemed later.
 *
 * Not covered by the BRD, which treats a voucher as one of three reward shapes and
 * stops there. Once it is spent on a later visit it becomes an instrument with an
 * owner, a value, an expiry and exactly one permitted use, and each of those is
 * tested here because each is a way to lose money.
 */
class VoucherTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private Customer $customer;

    private User $rep;

    private VoucherService $vouchers;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->merchant = Merchant::factory()->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();
        $this->rep = User::factory()->salesRep($this->branch)->create();

        app(TenantContext::class)->set($this->merchant->id);

        $this->customer = Customer::create(['phone' => '0991234567', 'name' => 'Test Customer']);
        $this->vouchers = app(VoucherService::class);
    }

    private function rule(array $overrides = []): LoyaltyRule
    {
        return LoyaltyRule::create([
            ...LoyaltyRule::defaults(),
            'reward_type' => RewardType::Voucher,
            'reward_value' => 50,
            'max_discount_amount' => null,
            'effective_from' => now()->subMonth()->toDateString(),
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function redemption(LoyaltyRule $rule, ?Customer $customer = null): Redemption
    {
        return Redemption::create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'branch_id' => $this->branch->id,
            'loyalty_rule_id' => $rule->id,
            'cycle_number' => 1,
            'cycle_total_amount' => 1200,
            'cycle_invoice_count' => 4,
            'reward_type' => RewardType::Voucher,
            'computed_amount' => 50,
            'discount_amount' => 50,
            'performed_by' => $this->rep->id,
            'redeemed_at' => now(),
        ]);
    }

    private function invoice(?Customer $customer = null, string $number = 'INV-1'): Invoice
    {
        return Invoice::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->rep->id,
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_number' => $number,
            'amount' => 300,
            'invoice_date' => now()->toDateString(),
        ]);
    }

    // -----------------------------------------------------------------
    // Issuing
    // -----------------------------------------------------------------

    public function test_a_voucher_is_issued_with_a_code_value_and_expiry(): void
    {
        $rule = $this->rule(['voucher_validity_days' => 30]);

        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        $this->assertSame('50.00', $voucher->amount);
        $this->assertSame(VoucherStatus::Issued, $voucher->status);
        $this->assertSame($this->customer->id, $voucher->customer_id);
        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $voucher->expires_at->toDateString(),
        );
        $this->assertTrue($voucher->isUsable());
    }

    public function test_the_code_avoids_characters_that_are_misread_aloud(): void
    {
        $rule = $this->rule();

        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        // A customer reads this over a counter, so 0/O and 1/I are left out.
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{10}$/', $voucher->code);
    }

    public function test_a_rule_with_no_validity_issues_a_voucher_that_never_expires(): void
    {
        $rule = $this->rule(['voucher_validity_days' => null]);

        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        $this->assertNull($voucher->expires_at);
        $this->assertFalse($voucher->isExpired());
        $this->assertTrue($voucher->isUsable());
    }

    // -----------------------------------------------------------------
    // Accepting — and the single-use guarantee
    // -----------------------------------------------------------------

    public function test_a_voucher_can_be_accepted_once(): void
    {
        $rule = $this->rule();
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);
        $invoice = $this->invoice();

        $accepted = $this->vouchers->accept($voucher, $invoice, $this->rep);

        $this->assertSame(VoucherStatus::Used, $accepted->status);
        $this->assertNotNull($accepted->used_at);
        $this->assertSame($invoice->id, $accepted->used_on_invoice_id);
        $this->assertSame($this->branch->id, $accepted->used_at_branch_id);
        $this->assertSame($this->rep->id, $accepted->accepted_by);
        $this->assertFalse($accepted->isUsable());
    }

    public function test_a_second_attempt_on_the_same_voucher_is_refused(): void
    {
        $rule = $this->rule();
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        $this->vouchers->accept($voucher, $this->invoice(), $this->rep);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->vouchers->accept($voucher->fresh(), $this->invoice(number: 'INV-2'), $this->rep);
    }

    public function test_two_tills_racing_for_the_same_voucher_leave_one_winner(): void
    {
        $rule = $this->rule();
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        $first = $this->invoice(number: 'INV-A');
        $second = $this->invoice(number: 'INV-B');

        /*
         * Both hold a stale instance that still says "issued" — the state two
         * concurrent requests would be in after each read the row. Only the
         * conditional update can tell them apart, which is why accept() does not
         * read-then-write.
         */
        $stale = Voucher::findOrFail($voucher->id);
        $alsoStale = Voucher::findOrFail($voucher->id);

        $this->vouchers->accept($stale, $first, $this->rep);

        try {
            $this->vouchers->accept($alsoStale, $second, $this->rep);
            $this->fail('The same voucher was spent twice.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException|\Illuminate\Validation\ValidationException) {
            // Either refusal is correct; what matters is that it was refused.
        }

        $voucher->refresh();

        $this->assertSame(VoucherStatus::Used, $voucher->status);
        $this->assertSame($first->id, $voucher->used_on_invoice_id);
    }

    public function test_an_expired_voucher_is_refused(): void
    {
        $rule = $this->rule(['voucher_validity_days' => 30]);
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        $this->travel(31)->days();

        $this->assertFalse($voucher->fresh()->isUsable());
        $this->assertSame('expired', $voucher->fresh()->state());

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->vouchers->accept($voucher->fresh(), $this->invoice(), $this->rep);
    }

    public function test_a_voucher_cannot_be_spent_by_another_customer(): void
    {
        $rule = $this->rule();
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        $someoneElse = Customer::create(['phone' => '0999999999', 'name' => 'Other Customer']);

        // A reward belongs to whoever earned it; letting it transfer would turn it
        // into a bearer instrument and break the trail back to the cycle.
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->vouchers->accept($voucher, $this->invoice($someoneElse, 'INV-9'), $this->rep);
    }

    public function test_a_voucher_cannot_be_spent_on_an_unlinked_invoice(): void
    {
        $rule = $this->rule();
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        // BRD BR-022: a customer who refuses to give a number still gets a recorded
        // sale, but it belongs to nobody — so no voucher can attach to it.
        $unlinked = Invoice::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->rep->id,
            'customer_id' => null,
            'invoice_number' => 'INV-ANON',
            'amount' => 300,
            'invoice_date' => now()->toDateString(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->vouchers->accept($voucher, $unlinked, $this->rep);
    }

    // -----------------------------------------------------------------
    // Isolation
    // -----------------------------------------------------------------

    public function test_a_code_from_another_merchant_does_not_exist_here(): void
    {
        $other = Merchant::factory()->create();

        // Built inside the other merchant's context: with this one pinned, writing
        // their rows would rightly be refused as a cross-tenant write.
        $foreignCode = app(TenantContext::class)->for($other->id, function () use ($other) {
            $otherBranch = Branch::factory()->for($other)->create();
            $otherRep = User::factory()->salesRep($otherBranch)->create();
            $customer = Customer::create(['phone' => '0988888888', 'name' => 'Their Customer']);

            $rule = LoyaltyRule::create([
                ...LoyaltyRule::defaults(),
                'reward_type' => RewardType::Voucher,
                'reward_value' => 50,
                'max_discount_amount' => null,
                'effective_from' => now()->subMonth()->toDateString(),
            ]);

            $redemption = Redemption::create([
                'customer_id' => $customer->id,
                'branch_id' => $otherBranch->id,
                'loyalty_rule_id' => $rule->id,
                'cycle_number' => 1,
                'reward_type' => RewardType::Voucher,
                'computed_amount' => 50,
                'discount_amount' => 50,
                'performed_by' => $otherRep->id,
                'redeemed_at' => now(),
            ]);

            return app(VoucherService::class)->issue($redemption, $rule)->code;
        });

        // The spirit of BR-002 applied to vouchers: a code only means something in
        // the store that issued it.
        $this->assertNull($this->vouchers->findByCode($foreignCode));
    }

    // -----------------------------------------------------------------
    // Lookup and withdrawal
    // -----------------------------------------------------------------

    public function test_a_code_is_found_however_it_was_typed(): void
    {
        $rule = $this->rule();
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);

        $spaced = strtolower(substr($voucher->code, 0, 5).' - '.substr($voucher->code, 5));

        $this->assertSame($voucher->id, $this->vouchers->findByCode($spaced)?->id);
    }

    public function test_only_spendable_vouchers_are_listed_for_a_customer(): void
    {
        $rule = $this->rule(['voucher_validity_days' => 30]);

        $usable = $this->vouchers->issue($this->redemption($rule), $rule);
        $spent = $this->vouchers->issue($this->redemption($rule), $rule);
        $this->vouchers->accept($spent, $this->invoice(), $this->rep);

        $expired = $this->vouchers->issue($this->redemption($rule), $rule);
        $expired->forceFill(['expires_at' => now()->subDay()])->save();

        $listed = $this->vouchers->usableFor($this->customer);

        $this->assertCount(1, $listed);
        $this->assertSame($usable->id, $listed->first()->id);
    }

    public function test_an_unused_voucher_can_be_withdrawn(): void
    {
        $rule = $this->rule();
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);
        $owner = User::factory()->owner($this->merchant->id)->create();

        $withdrawn = $this->vouchers->cancel($voucher, $owner, 'The invoice that earned it was cancelled');

        $this->assertSame(VoucherStatus::Cancelled, $withdrawn->status);
        $this->assertFalse($withdrawn->isUsable());
    }

    public function test_a_spent_voucher_cannot_be_withdrawn(): void
    {
        $rule = $this->rule();
        $voucher = $this->vouchers->issue($this->redemption($rule), $rule);
        $owner = User::factory()->owner($this->merchant->id)->create();

        $this->vouchers->accept($voucher, $this->invoice(), $this->rep);

        // The customer has had the value; rewriting that would falsify the invoice
        // it was spent against.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);

        $this->vouchers->cancel($voucher->fresh(), $owner, 'Changed my mind');
    }

    // -----------------------------------------------------------------
    // Audit
    // -----------------------------------------------------------------

    public function test_issuing_accepting_and_withdrawing_are_all_logged(): void
    {
        $rule = $this->rule();
        $owner = User::factory()->owner($this->merchant->id)->create();

        $spent = $this->vouchers->issue($this->redemption($rule), $rule);
        $this->vouchers->accept($spent, $this->invoice(), $this->rep);

        $withdrawn = $this->vouchers->issue($this->redemption($rule), $rule);
        $this->vouchers->cancel($withdrawn, $owner, 'Issued in error');

        // A voucher moves real value, so each step has to be attributable.
        $this->assertSame(2, AuditLog::where('action', 'voucher.issued')->count());
        $this->assertSame(1, AuditLog::where('action', 'voucher.accepted')->count());
        $this->assertSame(1, AuditLog::where('action', 'voucher.cancelled')->count());

        $accepted = AuditLog::where('action', 'voucher.accepted')->firstOrFail();
        $this->assertSame($this->rep->id, $accepted->user_id);
    }
}
