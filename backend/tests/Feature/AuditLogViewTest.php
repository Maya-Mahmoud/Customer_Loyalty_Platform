<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading the audit trail (BRD FR-SEC-02, 7.2, section 20).
 *
 * The trail exists to answer "who did this, and when". So the cases here are about
 * who is allowed to ask, whose entries they get back — an owner must never see
 * another store's, and the platform supervisor must see the entries that belong to no
 * store at all — and the fact that nothing can write to it through the API.
 */
class AuditLogViewTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private User $admin;

    private User $owner;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();

        $this->admin = User::factory()->platformAdmin()->create();
        $this->owner = User::factory()->owner($this->merchant->id)->create(['name' => 'The Owner']);
        $this->manager = User::factory()->branchManager($this->branch)->create();
        $this->rep = User::factory()->salesRep($this->branch)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function log(array $attributes = []): AuditLog
    {
        return AuditLog::create([
            'merchant_id' => $this->merchant->id,
            'user_id' => $this->owner->id,
            'action' => 'invoice.recorded',
            'ip_address' => '127.0.0.1',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function read(User $user, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/audit-logs?' . http_build_query($query));
    }

    // -----------------------------------------------------------------
    // Who may read it (BRD 7.2)
    // -----------------------------------------------------------------

    public function test_only_the_owner_and_the_platform_supervisor_may_read_it(): void
    {
        $this->log();

        $this->read($this->owner)->assertOk();
        $this->read($this->admin)->assertOk();

        // A trail readable by the people it records is a different thing entirely.
        $this->read($this->manager)->assertForbidden();
        $this->read($this->rep)->assertForbidden();
    }

    public function test_an_owner_sees_their_own_stores_entries_and_no_others(): void
    {
        $this->log(['action' => 'ledger.adjusted']);

        $other = Merchant::factory()->create();
        $otherOwner = User::factory()->owner($other->id)->create();

        AuditLog::create([
            'merchant_id' => $other->id,
            'user_id' => $otherOwner->id,
            'action' => 'customers.exported',
        ]);

        $response = $this->read($this->owner)->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'ledger.adjusted')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_supervisor_sees_platform_entries_that_belong_to_no_store(): void
    {
        $this->log(['action' => 'invoice.recorded']);

        // A platform-level action: no merchant, and the supervisor is the only person
        // who should ever see it — a merchant filter would hide exactly these.
        AuditLog::create([
            'merchant_id' => null,
            'user_id' => $this->admin->id,
            'action' => 'merchant.activated',
        ]);

        $this->read($this->admin)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // The owner sees only their own, still.
        $this->read($this->owner)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // -----------------------------------------------------------------
    // What an entry says
    // -----------------------------------------------------------------

    public function test_an_entry_names_the_actor_the_change_and_the_time(): void
    {
        app(TenantContext::class)->set($this->merchant->id);

        $customer = Customer::create(['phone' => '0991234567', 'name' => 'Sami']);

        $rule = LoyaltyRule::create([
            ...LoyaltyRule::defaults(),
            'effective_from' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);

        $redemption = Redemption::create([
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'loyalty_rule_id' => $rule->id,
            'cycle_number' => 1,
            'cycle_total_amount' => 1000,
            'cycle_invoice_count' => 2,
            'reward_type' => 'percentage',
            'computed_amount' => 100,
            'discount_amount' => 50,
            'carried_over_amount' => 0,
            'performed_by' => $this->owner->id,
            'redeemed_at' => now(),
        ]);

        app(TenantContext::class)->forget();

        $this->log([
            'action' => 'redemption.paid',
            'entity_type' => Redemption::class,
            'entity_id' => $redemption->id,
            'before' => ['balance' => 1000],
            'after' => ['discount_amount' => 50, 'reason' => 'Threshold reached'],
        ]);

        $this->read($this->owner)
            ->assertOk()
            ->assertJsonPath('data.0.action', 'redemption.paid')
            ->assertJsonPath('data.0.user.name', 'The Owner')
            ->assertJsonPath('data.0.user.role', 'merchant_owner')
            // The short name, not the class path: it tells the reader what was acted
            // on rather than how this application is organised.
            ->assertJsonPath('data.0.entity_type', 'Redemption')
            ->assertJsonPath('data.0.entity_id', $redemption->id)
            ->assertJsonPath('data.0.before.balance', 1000)
            ->assertJsonPath('data.0.after.discount_amount', 50)
            ->assertJsonPath('data.0.ip_address', '127.0.0.1');
    }

    public function test_newest_first_because_that_is_what_anyone_opens_it_for(): void
    {
        $this->log(['action' => 'first.action']);
        $this->log(['action' => 'second.action']);
        $this->log(['action' => 'third.action']);

        $this->read($this->owner)
            ->assertOk()
            ->assertJsonPath('data.0.action', 'third.action')
            ->assertJsonPath('data.2.action', 'first.action');
    }

    // -----------------------------------------------------------------
    // Finding one entry among thousands
    // -----------------------------------------------------------------

    public function test_a_family_of_actions_can_be_filtered_by_its_prefix(): void
    {
        $this->log(['action' => 'merchant.activated']);
        $this->log(['action' => 'merchant.suspended']);
        $this->log(['action' => 'invoice.recorded']);

        // "merchant." brings the whole family without the reader having to know
        // every action name in it.
        $this->read($this->owner, ['action' => 'merchant.'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->read($this->owner, ['action' => 'invoice.recorded'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_it_can_be_filtered_by_person_by_date_and_by_entity(): void
    {
        $this->log(['action' => 'by.owner']);
        $this->log(['action' => 'by.manager', 'user_id' => $this->manager->id]);

        $old = $this->log(['action' => 'last.month']);
        $old->forceFill(['created_at' => now()->subMonth()])->saveQuietly();

        $this->log([
            'action' => 'on.customer',
            'entity_type' => Customer::class,
            'entity_id' => 42,
        ]);

        $this->read($this->owner, ['user_id' => $this->manager->id])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'by.manager');

        $this->read($this->owner, ['from' => now()->startOfMonth()->toDateString()])
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->read($this->owner, ['entity_type' => 'Customer', 'entity_id' => 42])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'on.customer');
    }

    public function test_the_filter_list_is_built_from_what_actually_happened(): void
    {
        $this->log(['action' => 'ledger.adjusted']);
        $this->log(['action' => 'ledger.adjusted']);
        $this->log(['action' => 'customers.exported']);

        $actions = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/audit-logs/actions')
            ->assertOk()
            ->json('data');

        // Read from the data, so a newly audited action appears the first time it
        // happens rather than when someone remembers to add it to a list.
        $this->assertSame(['customers.exported', 'ledger.adjusted'], $actions);
    }

    public function test_long_trails_are_paginated(): void
    {
        for ($i = 0; $i < 45; $i++) {
            $this->log(['action' => 'invoice.recorded']);
        }

        $this->read($this->owner)
            ->assertOk()
            ->assertJsonCount(40, 'data')
            ->assertJsonPath('meta.total', 45)
            ->assertJsonPath('meta.last_page', 2);

        $this->read($this->owner, ['page' => 2])
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    // -----------------------------------------------------------------
    // The shape of the trail, for the panel above the list
    // -----------------------------------------------------------------

    public function test_the_statistics_fold_the_long_tail_into_one_bucket(): void
    {
        foreach (['a.one', 'a.one', 'a.one', 'b.two', 'b.two', 'c.three', 'd.four', 'e.five', 'f.six'] as $action) {
            $this->log(['action' => $action]);
        }

        $stats = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/audit-logs/stats')
            ->assertOk()
            ->json();

        $this->assertSame(9, $stats['total']);
        // Five slices and a bucket: twenty slices communicate nothing, and the tail
        // of an audit trail is long by nature.
        $this->assertCount(5, $stats['by_action']);
        $this->assertSame('a.one', $stats['by_action'][0]['action']);
        $this->assertSame(3, $stats['by_action'][0]['total']);
        $this->assertSame(1, $stats['other_total']);
    }

    public function test_the_hourly_shape_keeps_its_quiet_hours(): void
    {
        $this->log()->forceFill(['created_at' => now()->setTime(9, 15)])->saveQuietly();
        $this->log()->forceFill(['created_at' => now()->setTime(9, 40)])->saveQuietly();
        $this->log()->forceFill(['created_at' => now()->setTime(20, 5)])->saveQuietly();

        $byHour = collect(
            $this->actingAs($this->owner, 'sanctum')
                ->getJson('/api/v1/audit-logs/stats')
                ->assertOk()
                ->json('by_hour')
        )->keyBy('hour');

        // All twenty-four, including the empty ones: a quiet hour is part of the
        // shape, and skipping it draws a working day that never existed.
        $this->assertCount(24, $byHour);
        $this->assertSame(2, $byHour[9]['total']);
        $this->assertSame(1, $byHour[20]['total']);
        $this->assertSame(0, $byHour[3]['total']);
    }

    public function test_the_statistics_obey_the_filters_on_screen(): void
    {
        $this->log(['action' => 'merchant.activated']);
        $this->log(['action' => 'merchant.suspended']);
        $this->log(['action' => 'invoice.recorded']);

        // A chart that ignores the filter beside it is a chart that lies.
        $stats = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/audit-logs/stats?action=merchant.')
            ->assertOk()
            ->json();

        $this->assertSame(2, $stats['total']);
    }

    public function test_the_statistics_stay_inside_the_stores_own_trail(): void
    {
        $this->log();

        $other = Merchant::factory()->create();

        AuditLog::create([
            'merchant_id' => $other->id,
            'user_id' => User::factory()->owner($other->id)->create()->id,
            'action' => 'their.action',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/audit-logs/stats')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    // -----------------------------------------------------------------
    // It is a record, not a document
    // -----------------------------------------------------------------

    public function test_there_is_no_way_to_write_to_the_trail_through_the_api(): void
    {
        $entry = $this->log();

        // A trail anyone could edit answers no question worth asking.
        foreach (
            [
                ['post', '/api/v1/audit-logs'],
                ['put', "/api/v1/audit-logs/{$entry->id}"],
                ['delete', "/api/v1/audit-logs/{$entry->id}"],
            ] as [$method, $url]
        ) {
            $status = $this->actingAs($this->admin, 'sanctum')->json($method, $url)->status();

            // 404 where no route exists, 405 where the URI answers only GET —
            // either way there is nothing to write to.
            $this->assertContains($status, [404, 405], $method . ' ' . $url);
        }

        $this->assertSame(1, AuditLog::count());
    }
}
