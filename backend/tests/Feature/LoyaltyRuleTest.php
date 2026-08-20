<?php

namespace Tests\Feature;

use App\Enums\RewardType;
use App\Enums\ThresholdType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Publishing and versioning the loyalty rule (BRD 8.3, FR-LOY-01 to FR-LOY-08).
 *
 * The heart of it is BR-015: a change must never reach back over invoices already
 * recorded. That is enforced structurally — a change writes a new version and
 * closes the old one — so most of these cases check that the history stays intact.
 */
class LoyaltyRuleTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->merchant = Merchant::factory()->create();
        $this->owner = User::factory()->owner($this->merchant->id)->create();
    }

    private function asOwner(): self
    {
        $this->actingAs($this->owner, 'sanctum');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'threshold_type' => 'amount',
            'threshold_amount' => 1000,
            'threshold_invoice_count' => null,
            'reward_type' => 'percentage',
            'reward_value' => 10,
            'max_discount_amount' => 50,
            'min_invoice_amount' => 10,
            'accumulation_scope' => 'merchant',
            'reset_policy' => 'carry_over',
            'balance_validity_months' => 12,
            ...$overrides,
        ];
    }

    // -----------------------------------------------------------------
    // Access (BRD 7.2)
    // -----------------------------------------------------------------

    public function test_only_the_owner_configures_the_rule(): void
    {
        $branch = Branch::factory()->for($this->merchant)->create();
        $manager = User::factory()->branchManager($branch)->create();
        $rep = User::factory()->salesRep($branch)->create();

        foreach ([$manager, $rep] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/loyalty-rule')->assertStatus(403);
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/loyalty-rule', $this->form())->assertStatus(403);
        }
    }

    // -----------------------------------------------------------------
    // Publishing (FR-LOY-01 to FR-LOY-07)
    // -----------------------------------------------------------------

    public function test_the_first_rule_becomes_version_one_and_takes_effect_today(): void
    {
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form())
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.effective_from', now()->toDateString())
            // Null on the version in force; only superseded ones carry an end date.
            ->assertJsonPath('data.effective_to', null);
    }

    public function test_the_settings_screen_gets_the_defaults_of_the_brd(): void
    {
        // BRD 11.1, so a merchant who has configured nothing still sees sensible
        // starting values rather than an empty form.
        $this->asOwner()->getJson('/api/v1/loyalty-rule')
            ->assertOk()
            ->assertJsonPath('current', null)
            ->assertJsonPath('defaults.threshold_amount', 1000)
            ->assertJsonPath('defaults.reward_value', 10)
            ->assertJsonPath('defaults.max_discount_amount', 50)
            ->assertJsonPath('defaults.min_invoice_amount', 10)
            ->assertJsonPath('defaults.reset_policy', 'carry_over')
            ->assertJsonPath('defaults.accumulation_scope', 'merchant');
    }

    public function test_each_threshold_and_reward_combination_can_be_published(): void
    {
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form([
            'threshold_type' => 'invoice_count',
            'threshold_amount' => null,
            'threshold_invoice_count' => 10,
            'reward_type' => 'voucher',
            'reward_value' => 50,
            'max_discount_amount' => null,
        ]))->assertCreated()
            ->assertJsonPath('data.threshold_type', 'invoice_count')
            ->assertJsonPath('data.reward_type', 'voucher');

        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form([
            'threshold_type' => 'both',
            'threshold_amount' => 500,
            'threshold_invoice_count' => 5,
            'reward_type' => 'fixed_amount',
            'reward_value' => 25,
            'max_discount_amount' => null,
            'effective_from' => now()->addDay()->toDateString(),
        ]))->assertCreated()
            ->assertJsonPath('data.version', 2);
    }

    // -----------------------------------------------------------------
    // Consistency between the chosen types and the fields
    // -----------------------------------------------------------------

    public function test_an_amount_threshold_needs_an_amount(): void
    {
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form(['threshold_amount' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('threshold_amount');
    }

    public function test_an_invoice_count_threshold_needs_a_count(): void
    {
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form([
                'threshold_type' => 'invoice_count',
                'threshold_invoice_count' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('threshold_invoice_count');
    }

    public function test_a_combined_threshold_needs_both_figures(): void
    {
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form([
                'threshold_type' => 'both',
                'threshold_invoice_count' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('threshold_invoice_count');
    }

    public function test_a_percentage_reward_needs_a_ceiling(): void
    {
        // BRD BR-021 exists to bound the merchant's exposure on a large cycle; a
        // percentage with no ceiling has none.
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form(['max_discount_amount' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_discount_amount');
    }

    public function test_a_percentage_over_one_hundred_is_refused(): void
    {
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form(['reward_value' => 150]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reward_value');
    }

    public function test_a_flat_reward_needs_no_ceiling(): void
    {
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form([
                'reward_type' => 'fixed_amount',
                'reward_value' => 30,
                'max_discount_amount' => null,
            ]))
            ->assertCreated();
    }

    // -----------------------------------------------------------------
    // BR-015 — no retroactive effect
    // -----------------------------------------------------------------

    public function test_a_change_publishes_a_new_version_and_closes_the_old_one(): void
    {
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form())->assertCreated();

        $tomorrow = now()->addDay()->toDateString();

        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form([
                'threshold_amount' => 2000,
                'effective_from' => $tomorrow,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.effective_from', $tomorrow);

        $first = LoyaltyRule::withoutGlobalScopes()->where('version', 1)->firstOrFail();

        // The old version is not deleted or edited: it is closed the day before the
        // new one starts, so no date is ever governed by two rules.
        $this->assertSame(now()->toDateString(), $first->effective_to->toDateString());
        $this->assertFalse($first->is_active);
        $this->assertSame('1000.00', $first->threshold_amount);
    }

    public function test_the_rule_in_force_is_the_one_matching_the_date(): void
    {
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form())->assertCreated();
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form([
            'threshold_amount' => 2000,
            'effective_from' => now()->addDays(3)->toDateString(),
        ]))->assertCreated();

        // A customer part way to 1,000 keeps that threshold until the new version
        // actually starts — the whole point of BR-015.
        $this->assertSame('1000.00', $this->merchant->ruleEffectiveOn()->threshold_amount);
        $this->assertSame('1000.00', $this->merchant->ruleEffectiveOn(now()->addDay()->toDateString())->threshold_amount);
        $this->assertSame('2000.00', $this->merchant->ruleEffectiveOn(now()->addDays(3)->toDateString())->threshold_amount);
        $this->assertSame('2000.00', $this->merchant->ruleEffectiveOn(now()->addYear()->toDateString())->threshold_amount);
    }

    public function test_a_rule_cannot_start_in_the_past(): void
    {
        // Backdating would rewrite the rule that already governed recorded
        // invoices, which is exactly what BR-015 forbids.
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form([
                'effective_from' => now()->subDay()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');
    }

    public function test_a_new_version_cannot_start_on_or_before_the_current_one(): void
    {
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form())->assertCreated();

        // Two versions sharing a start date would leave ruleEffectiveOn guessing.
        $this->asOwner()
            ->postJson('/api/v1/loyalty-rule', $this->form(['threshold_amount' => 2000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_from');
    }

    public function test_the_history_keeps_every_version(): void
    {
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form())->assertCreated();
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form([
            'threshold_amount' => 2000,
            'effective_from' => now()->addDay()->toDateString(),
        ]))->assertCreated();

        $response = $this->asOwner()->getJson('/api/v1/loyalty-rule')->assertOk();

        // BRD FR-LOY-08: an auditable copy of each rule with its effective date.
        $this->assertCount(2, $response->json('history'));
        $this->assertSame(2, $response->json('history.0.version'));
        $this->assertSame($this->owner->name, $response->json('history.0.created_by'));
        $this->assertSame(1, $response->json('history.1.version'));
    }

    // -----------------------------------------------------------------
    // Isolation and audit
    // -----------------------------------------------------------------

    public function test_another_merchants_rule_is_invisible(): void
    {
        $other = Merchant::factory()->create();
        $otherOwner = User::factory()->owner($other->id)->create();

        $this->actingAs($otherOwner, 'sanctum')
            ->postJson('/api/v1/loyalty-rule', $this->form(['threshold_amount' => 7777]))
            ->assertCreated();

        $response = $this->asOwner()->getJson('/api/v1/loyalty-rule')->assertOk();

        $this->assertNull($response->json('current'));
        $this->assertCount(0, $response->json('history'));
    }

    public function test_publishing_is_written_to_the_audit_log(): void
    {
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form())->assertCreated();
        $this->asOwner()->postJson('/api/v1/loyalty-rule', $this->form([
            'threshold_amount' => 2000,
            'effective_from' => now()->addDay()->toDateString(),
        ]))->assertCreated();

        $this->assertSame(1, AuditLog::where('action', 'loyalty_rule.created')->count());

        $superseded = AuditLog::where('action', 'loyalty_rule.superseded')->firstOrFail();

        // Both sides are recorded, so a dispute over which threshold applied can be
        // answered from the log rather than from memory.
        $this->assertSame('1000.00', $superseded->before['threshold_amount']);
        $this->assertSame('2000.00', $superseded->after['threshold_amount']);
        $this->assertSame($this->owner->id, $superseded->user_id);
    }
}
