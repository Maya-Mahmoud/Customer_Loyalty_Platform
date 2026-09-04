<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\MerchantStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer looking themselves up (BRD FR-CUS-12).
 *
 * This is a public endpoint about private data, so most of these cases are about what
 * it refuses. The proof is a receipt rather than a password, and the whole design
 * rests on two things holding: a receipt from one shop unlocks that shop and no
 * other, and every failure answers identically so the endpoint cannot be used to
 * discover who shops where.
 */
class CustomerBalanceLookupTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $alnoor;

    private Branch $branch;

    private User $rep;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alnoor = Merchant::factory()->create([
            'name' => 'Al Noor Stores',
            'trade_name' => 'Al Noor',
            'city' => 'Damascus',
            'status' => MerchantStatus::Active,
        ]);

        $this->branch = Branch::factory()->for($this->alnoor)->create();
        $this->rep = User::factory()->salesRep($this->branch)->create();

        $this->publishRule($this->alnoor);

        $this->customer = $this->customerAt($this->alnoor, '0991234567', 'Sami');
        $this->sale($this->alnoor, $this->customer, 'INV-100', 600);
    }

    private function publishRule(Merchant $merchant, array $overrides = []): LoyaltyRule
    {
        return LoyaltyRule::withoutGlobalScopes()->create([
            ...LoyaltyRule::defaults(),
            'merchant_id' => $merchant->id,
            'effective_from' => now()->subMonth()->toDateString(),
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function customerAt(Merchant $merchant, string $phone, string $name): Customer
    {
        return app(TenantContext::class)->for(
            $merchant->id,
            fn () => Customer::create(['phone' => $phone, 'name' => $name])
        );
    }

    /** A sale and the ledger entry it produces, the way the till writes them. */
    private function sale(Merchant $merchant, Customer $customer, string $number, float $amount, ?string $date = null): Invoice
    {
        $branch = Branch::withoutGlobalScopes()->where('merchant_id', $merchant->id)->firstOrFail();
        $user = User::withoutGlobalScopes()->where('merchant_id', $merchant->id)->firstOrFail();

        $invoice = Invoice::withoutGlobalScopes()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => $number,
            'amount' => $amount,
            'invoice_date' => $date ?? now()->toDateString(),
        ]);

        LedgerEntry::withoutGlobalScopes()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'cycle_number' => 1,
            'type' => LedgerEntryType::Accrual,
            'amount' => $amount,
            'invoice_count_delta' => 1,
        ]);

        $customer->forceFill(['last_purchase_at' => $invoice->invoice_date])->saveQuietly();

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function ask(array $claim): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/balance', [
            'merchant_id' => $this->alnoor->id,
            'phone' => '0991234567',
            ...$claim,
        ]);
    }

    // -----------------------------------------------------------------
    // It works without an account
    // -----------------------------------------------------------------

    public function test_a_customer_sees_their_balance_with_a_receipt_number(): void
    {
        // No token, no session — the customer has no account to sign in to (BR-001).
        $this->ask(['invoice_number' => 'INV-100'])
            ->assertOk()
            ->assertJsonPath('data.store', 'Al Noor')
            ->assertJsonPath('data.name', 'Sami')
            ->assertJsonPath('data.balance', 600)
            ->assertJsonPath('data.amount_remaining', 400)
            ->assertJsonPath('data.is_eligible', false)
            // The reward in the customer's own terms: a balance without it is a
            // number with no meaning.
            ->assertJsonPath('data.reward.type', 'percentage');
    }

    public function test_the_shop_list_is_public_and_names_only_active_shops(): void
    {
        $pending = Merchant::factory()->create(['name' => 'Not Open Yet', 'status' => MerchantStatus::Pending]);

        $names = collect($this->getJson('/api/v1/balance/stores')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertTrue($names->contains('Al Noor'));
        $this->assertFalse($names->contains('Not Open Yet'));
    }

    public function test_a_lost_receipt_falls_back_to_the_date_and_the_amount(): void
    {
        $this->ask([
            'invoice_date' => now()->toDateString(),
            'amount' => 600,
        ])
            ->assertOk()
            ->assertJsonPath('data.balance', 600);
    }

    public function test_the_fallback_only_ever_matches_the_latest_purchase(): void
    {
        // An older purchase, whose date and amount somebody might remember.
        $this->sale($this->alnoor, $this->customer, 'INV-090', 250, now()->subMonth()->toDateString());
        // ...and a newer one, which is now the latest.
        $this->sale($this->alnoor, $this->customer, 'INV-110', 900);

        // Quoting the old one is refused: the fallback cannot be walked backwards
        // through a customer's history.
        $this->ask(['invoice_date' => now()->subMonth()->toDateString(), 'amount' => 250])
            ->assertStatus(404);

        $this->ask(['invoice_date' => now()->toDateString(), 'amount' => 900])
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // What it refuses
    // -----------------------------------------------------------------

    public function test_a_phone_number_alone_is_not_enough(): void
    {
        /*
         * The point of the whole design. A phone number is not a secret, and without
         * proof this endpoint would tell anyone where a person shops and how much
         * they spend.
         */
        $this->ask([])->assertStatus(404);
    }

    public function test_a_wrong_receipt_number_is_refused(): void
    {
        $this->ask(['invoice_number' => 'INV-101'])->assertStatus(404);
        $this->ask(['invoice_number' => 'INV-099'])->assertStatus(404);
    }

    public function test_an_unknown_number_answers_exactly_like_a_wrong_receipt(): void
    {
        $unknown = $this->postJson('/api/v1/balance', [
            'merchant_id' => $this->alnoor->id,
            'phone' => '0999999999',
            'invoice_number' => 'INV-100',
        ]);

        $wrongProof = $this->ask(['invoice_number' => 'NOPE']);

        // Identical, deliberately: a reply that told them apart would turn this into
        // a way of discovering who is registered with which shop.
        $this->assertSame($unknown->status(), $wrongProof->status());
        $this->assertSame($unknown->json('message'), $wrongProof->json('message'));
    }

    public function test_a_receipt_from_one_shop_never_opens_another(): void
    {
        $other = Merchant::factory()->create([
            'name' => 'Other Store',
            'trade_name' => 'Other Store',
            'status' => MerchantStatus::Active,
        ]);
        $otherBranch = Branch::factory()->for($other)->create();
        User::factory()->salesRep($otherBranch)->create();
        $this->publishRule($other);

        // The same person, shopping at both — two cards, two balances (BR-002).
        $theirCard = $this->customerAt($other, '0991234567', 'Sami');
        $this->sale($other, $theirCard, 'OTHER-1', 5000);

        // Their Al Noor receipt asked against the other shop: refused. Holding it
        // says nothing about their relationship with anybody else.
        $this->postJson('/api/v1/balance', [
            'merchant_id' => $other->id,
            'phone' => '0991234567',
            'invoice_number' => 'INV-100',
        ])->assertStatus(404);

        // The other shop's own receipt works, and shows that shop's balance alone.
        $this->postJson('/api/v1/balance', [
            'merchant_id' => $other->id,
            'phone' => '0991234567',
            'invoice_number' => 'OTHER-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.store', 'Other Store')
            ->assertJsonPath('data.balance', 5000);
    }

    public function test_a_suspended_shop_quotes_nothing(): void
    {
        $this->alnoor->forceFill(['status' => MerchantStatus::Suspended])->save();

        // Their data is retained (BR-020), but a balance nobody there can honour
        // today would be a promise rather than an answer.
        $this->ask(['invoice_number' => 'INV-100'])->assertStatus(404);
    }

    public function test_an_erased_customer_is_gone_from_here_too(): void
    {
        $this->customer->forceFill(['anonymized_at' => now()])->saveQuietly();

        // Section 16: erasure has to hold on every path, including the public one.
        $this->ask(['invoice_number' => 'INV-100'])->assertStatus(404);
    }

    public function test_the_answer_carries_nothing_about_the_shops_business(): void
    {
        $body = $this->ask(['invoice_number' => 'INV-100'])->assertOk()->json('data');

        // Their own position, and not one figure that describes the merchant: no
        // invoice list, no staff names, no totals for anybody else (BR-019).
        foreach (['invoices', 'invoice_number', 'entered_by', 'sales_total', 'customers'] as $leak) {
            $this->assertArrayNotHasKey($leak, $body);
        }
    }

    public function test_guessing_is_throttled(): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->ask(['invoice_number' => 'GUESS-' . $attempt])->assertStatus(404);
        }

        /*
         * Invoice numbers run in sequence, so the throttle is part of the control
         * rather than mere protection: without it, one receipt plus a phone number is
         * a starting point for walking a shop's numbering.
         */
        $this->ask(['invoice_number' => 'GUESS-9'])->assertStatus(429);
    }
}
