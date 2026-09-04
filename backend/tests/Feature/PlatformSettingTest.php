<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform's own settings (BRD FR-ADM-04): what it bills in, and what each plan
 * costs.
 *
 * The screen is the supervisor's alone, and these cases are mostly about the line
 * between the platform's money and a shop's money. A shop owner sets the currency
 * their own sales are priced in; nobody but the supervisor sets the currency the
 * platform charges in, or the price list itself.
 */
class PlatformSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->platformAdmin()->create();

        $this->plan = SubscriptionPlan::create([
            'code' => 'silver',
            'name' => 'Silver',
            'max_branches' => 3,
            'max_users' => 10,
            'max_monthly_invoices' => 2000,
            'monthly_price' => 150,
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // Who may reach it
    // -----------------------------------------------------------------

    public function test_only_the_platform_supervisor_reaches_the_settings(): void
    {
        $merchant = Merchant::factory()->create();
        $branch = Branch::factory()->for($merchant)->create();

        // Not even the store owner, who has every permission inside their own shop:
        // the price list is what the platform charges them, not a setting of theirs.
        $owner = User::factory()->owner($merchant->id)->create();
        $manager = User::factory()->branchManager($branch)->create();

        foreach ([$owner, $manager] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/settings')->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                    'monthly_price' => 1,
                    'max_branches' => null,
                    'max_users' => null,
                    'max_monthly_invoices' => null,
                ])->assertForbidden();
        }

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/settings')->assertOk();
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    public function test_the_currency_and_the_price_list_arrive_together(): void
    {
        /*
         * One request, deliberately. A price of 150 means nothing until you know what
         * money it is in, so the screen must never be able to paint a figure against
         * a currency from a different response.
         */
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.billing_currency', 'SYP')
            ->assertJsonPath('data.plans.0.code', 'silver')
            ->assertJsonPath('data.plans.0.monthly_price', '150.00')
            ->assertJsonPath('data.currencies', ['SYP', 'USD']);
    }

    public function test_a_retired_plan_is_still_shown_to_the_supervisor(): void
    {
        $retired = SubscriptionPlan::create([
            'code' => 'legacy',
            'name' => 'Legacy',
            'max_branches' => 1,
            'max_users' => 2,
            'max_monthly_invoices' => 100,
            'monthly_price' => 10,
            'is_active' => false,
        ]);

        // It still has shops on it, and the supervisor still has to be able to see —
        // and correct — what those shops are being charged. The public list used at
        // registration is the one that hides it.
        $codes = collect(
            $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/v1/admin/settings')->assertOk()->json('data.plans')
        )->pluck('code');

        $this->assertTrue($codes->contains('legacy'));

        $offered = collect(
            $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/v1/admin/subscription-plans')->assertOk()->json('data')
        )->pluck('code');

        $this->assertFalse($offered->contains('legacy'));
    }

    public function test_the_currency_falls_back_to_the_configured_default(): void
    {
        // Nothing has ever been saved, so the screen shows what a fresh installation
        // is actually running on rather than an empty field.
        $this->assertSame(0, PlatformSetting::count());
        $this->assertSame(config('clp.default_currency'), PlatformSetting::billingCurrency());
    }

    // -----------------------------------------------------------------
    // Changing the billing currency
    // -----------------------------------------------------------------

    public function test_the_supervisor_changes_the_billing_currency(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['billing_currency' => 'USD'])
            ->assertOk()
            ->assertJsonPath('data.billing_currency', 'USD');

        $this->assertSame('USD', PlatformSetting::billingCurrency());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.billing_currency_changed',
            'user_id' => $this->admin->id,
        ]);

        $entry = AuditLog::where('action', 'platform.billing_currency_changed')->firstOrFail();
        $this->assertSame('SYP', $entry->before['billing_currency']);
        $this->assertSame('USD', $entry->after['billing_currency']);
    }

    public function test_a_currency_the_platform_does_not_deal_in_is_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['billing_currency' => 'EUR'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('billing_currency');

        $this->assertSame('SYP', PlatformSetting::billingCurrency());
    }

    public function test_changing_the_billing_currency_leaves_every_shop_alone(): void
    {
        /*
         * The line this test exists to hold. A shop prices its own sales in its own
         * money (FR-MER-05); the platform bills in the platform's. Letting one rewrite
         * the other would silently restate every balance in a shop's ledger.
         */
        $merchant = Merchant::factory()->create(['currency' => 'SYP']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['billing_currency' => 'USD'])
            ->assertOk();

        $this->assertSame('SYP', $merchant->refresh()->currency);
    }

    // -----------------------------------------------------------------
    // Pricing a plan
    // -----------------------------------------------------------------

    public function test_the_supervisor_sets_what_a_plan_costs(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                'monthly_price' => 275.5,
                'max_branches' => 5,
                'max_users' => 20,
                'max_monthly_invoices' => 5000,
            ])
            ->assertOk()
            ->assertJsonPath('data.monthly_price', '275.50')
            ->assertJsonPath('data.max_branches', 5);

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.plan_updated']);
    }

    public function test_a_cap_can_be_removed_to_mean_unlimited(): void
    {
        // BRD FR-ADM-04: null is the unlimited tier. The field is 'present' in the
        // rules for exactly this — an absent key must not be read as "leave it".
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                'monthly_price' => 500,
                'max_branches' => null,
                'max_users' => null,
                'max_monthly_invoices' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.max_branches', null);

        $this->assertNull($this->plan->refresh()->max_branches);
    }

    public function test_a_free_tier_is_allowed_but_a_negative_price_is_not(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                'monthly_price' => 0,
                'max_branches' => 1,
                'max_users' => 1,
                'max_monthly_invoices' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('data.monthly_price', '0.00');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                'monthly_price' => -50,
                'max_branches' => 1,
                'max_users' => 1,
                'max_monthly_invoices' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monthly_price');
    }

    public function test_the_plan_code_cannot_be_rewritten(): void
    {
        // The code is how the seeders, the reports and the staff all refer to a plan.
        // It is not in the request rules, so a client sending one changes nothing.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                'code' => 'platinum',
                'monthly_price' => 150,
                'max_branches' => 3,
                'max_users' => 10,
                'max_monthly_invoices' => 2000,
            ])
            ->assertOk();

        $this->assertSame('silver', $this->plan->refresh()->code);
    }

    public function test_repricing_a_plan_does_not_touch_a_subscription_already_paid_for(): void
    {
        /*
         * A price change applies from the next renewal. A shop that has paid to the
         * end of the month keeps that date — repricing the platform must never be able
         * to shorten a subscription somebody has already bought.
         */
        $ends = now()->addMonths(2)->startOfDay();

        $merchant = Merchant::factory()->create([
            'subscription_plan_id' => $this->plan->id,
            'subscription_ends_at' => $ends,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                'monthly_price' => 900,
                'max_branches' => 3,
                'max_users' => 10,
                'max_monthly_invoices' => 2000,
            ])
            ->assertOk();

        $merchant->refresh();

        $this->assertSame($this->plan->id, $merchant->subscription_plan_id);
        $this->assertSame(
            $ends->toDateString(),
            $merchant->subscription_ends_at->toDateString()
        );
    }

    // -----------------------------------------------------------------
    // Adding and retiring a plan
    // -----------------------------------------------------------------

    public function test_the_supervisor_adds_a_plan_to_the_price_list(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/subscription-plans', [
                'code' => 'Gold',
                'name' => 'الخطة الذهبية',
                'monthly_price' => 400,
                'max_branches' => 10,
                'max_users' => 50,
                'max_monthly_invoices' => null,
            ])
            ->assertCreated()
            // Lower-cased on the way in: the code is an identifier, and "Gold"
            // beside "gold" would make every reference to one of them ambiguous.
            ->assertJsonPath('data.code', 'gold')
            ->assertJsonPath('data.name', 'الخطة الذهبية')
            ->assertJsonPath('data.max_monthly_invoices', null)
            // Created on sale: a plan nobody can be put on is not a plan yet.
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.plan_created']);
    }

    public function test_a_code_already_in_use_is_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/subscription-plans', [
                'code' => 'silver',
                'name' => 'Silver Again',
                'monthly_price' => 10,
                'max_branches' => null,
                'max_users' => null,
                'max_monthly_invoices' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, SubscriptionPlan::where('code', 'silver')->count());
    }

    public function test_a_plan_is_retired_rather_than_deleted(): void
    {
        /*
         * The shops on it keep pointing at it, so withdrawing a plan can only mean
         * withdrawing it from sale. It leaves the registration list and stays in the
         * console, where what those shops pay is still correctable.
         */
        $merchant = Merchant::factory()->create(['subscription_plan_id' => $this->plan->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                'monthly_price' => 150,
                'max_branches' => 3,
                'max_users' => 10,
                'max_monthly_invoices' => 2000,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame($this->plan->id, $merchant->refresh()->subscription_plan_id);

        $offered = collect(
            $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/v1/admin/subscription-plans')->assertOk()->json('data')
        )->pluck('code');

        $this->assertFalse($offered->contains('silver'));
    }

    public function test_an_unchanged_save_writes_no_audit_entry(): void
    {
        // Opening the screen and pressing save should not leave a trail suggesting
        // the price list moved. The currency is the exception, and says why.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/subscription-plans/{$this->plan->id}", [
                'monthly_price' => 150,
                'max_branches' => 3,
                'max_users' => 10,
                'max_monthly_invoices' => 2000,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'platform.plan_updated']);
    }
}
