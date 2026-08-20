<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Branch setup, BRD 8.2 step 1 and FR-BRN-01.
 */
class BranchManagementTest extends TestCase
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
        return ['name' => 'Damascus Branch', 'city' => 'Damascus', ...$overrides];
    }

    // -----------------------------------------------------------------
    // Access (BRD 7.2)
    // -----------------------------------------------------------------

    public function test_only_the_owner_manages_branches(): void
    {
        $branch = Branch::factory()->for($this->merchant)->create();
        $manager = User::factory()->branchManager($branch)->create();
        $rep = User::factory()->salesRep($branch)->create();
        $supervisor = User::factory()->platformAdmin()->create();

        // Neither branch managers nor reps hold branches.manage, and the platform
        // supervisor deliberately does not either — it is the owner's own setup.
        foreach ([$manager, $rep, $supervisor] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/branches')->assertStatus(403);
        }
    }

    public function test_branches_of_another_merchant_are_invisible(): void
    {
        Branch::factory()->for($this->merchant)->create(['name' => 'Mine']);

        $other = Merchant::factory()->create();
        $foreign = Branch::factory()->for($other)->create(['name' => 'Theirs']);

        $response = $this->asOwner()->getJson('/api/v1/branches')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mine', $response->json('data.0.name'));

        // Not merely hidden from the list: the id does not resolve at all.
        $this->asOwner()->putJson("/api/v1/branches/{$foreign->id}", $this->form())->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Creating and editing
    // -----------------------------------------------------------------

    public function test_a_branch_can_be_added(): void
    {
        $this->asOwner()
            ->postJson('/api/v1/branches', $this->form(['address' => 'Main St', 'phone' => '011 555 4444']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Damascus Branch')
            ->assertJsonPath('data.is_active', true)
            // Normalised on the way in, like every other phone in the system.
            ->assertJsonPath('data.phone', '0115554444');

        $this->assertDatabaseHas('branches', [
            'merchant_id' => $this->merchant->id,
            'name' => 'Damascus Branch',
        ]);
    }

    public function test_the_name_must_be_unique_within_the_merchant_but_not_across_the_platform(): void
    {
        Branch::factory()->for($this->merchant)->create(['name' => 'Damascus Branch']);

        // Built up front: once a request has run, the tenant context is pinned to
        // this merchant and creating another merchant's rows would rightly be
        // refused as a cross-tenant write.
        $other = Merchant::factory()->create();
        $otherOwner = User::factory()->owner($other->id)->create();

        $this->asOwner()->postJson('/api/v1/branches', $this->form())
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Another store may use the same branch name without any conflict.
        $this->actingAs($otherOwner, 'sanctum')
            ->postJson('/api/v1/branches', $this->form())
            ->assertCreated();
    }

    public function test_a_branch_can_be_edited(): void
    {
        $branch = Branch::factory()->for($this->merchant)->create(['city' => 'Damascus']);

        $this->asOwner()
            ->putJson("/api/v1/branches/{$branch->id}", $this->form([
                'name' => 'Renamed Branch',
                'city' => 'Aleppo',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Branch')
            ->assertJsonPath('data.city', 'Aleppo');
    }

    // -----------------------------------------------------------------
    // Switching off, never deleting
    // -----------------------------------------------------------------

    public function test_a_branch_is_switched_off_rather_than_deleted(): void
    {
        Branch::factory()->for($this->merchant)->create(['name' => 'Keep Me Active']);
        $branch = Branch::factory()->for($this->merchant)->create();

        $this->asOwner()
            ->postJson("/api/v1/branches/{$branch->id}/disable")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // The row survives, so the invoices recorded there keep their origin.
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'deleted_at' => null]);

        $this->asOwner()
            ->postJson("/api/v1/branches/{$branch->id}/enable")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_the_last_active_branch_cannot_be_switched_off(): void
    {
        $branch = Branch::factory()->for($this->merchant)->create();

        // With no active branch nobody could record a sale, and only the platform
        // supervisor could put it back.
        $this->asOwner()
            ->postJson("/api/v1/branches/{$branch->id}/disable")
            ->assertStatus(409);

        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_a_branch_with_staff_on_it_cannot_be_switched_off(): void
    {
        Branch::factory()->for($this->merchant)->create(['name' => 'Other Branch']);
        $branch = Branch::factory()->for($this->merchant)->create();
        User::factory()->salesRep($branch)->create();

        // Reassigning people silently would hide the decision from the owner.
        $this->asOwner()
            ->postJson("/api/v1/branches/{$branch->id}/disable")
            ->assertStatus(409);
    }

    public function test_disabled_staff_do_not_block_switching_a_branch_off(): void
    {
        Branch::factory()->for($this->merchant)->create(['name' => 'Other Branch']);
        $branch = Branch::factory()->for($this->merchant)->create();
        User::factory()->salesRep($branch)->disabled()->create();

        $this->asOwner()
            ->postJson("/api/v1/branches/{$branch->id}/disable")
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Plan caps (FR-ADM-04, BRD 8.2 exception)
    // -----------------------------------------------------------------

    public function test_the_plan_cap_blocks_another_branch(): void
    {
        $plan = SubscriptionPlan::create([
            'code' => 'starter', 'name' => 'Starter',
            'max_branches' => 1, 'max_users' => 3, 'max_monthly_invoices' => 500, 'monthly_price' => 15,
        ]);
        $this->merchant->update(['subscription_plan_id' => $plan->id]);

        Branch::factory()->for($this->merchant)->create();

        $this->asOwner()->postJson('/api/v1/branches', $this->form())->assertStatus(409);
    }

    public function test_a_merchant_without_a_plan_is_not_capped(): void
    {
        // Assigning a plan is the supervisor's job; blocking the store because that
        // has not happened would punish them for someone else's omission.
        $this->merchant->update(['subscription_plan_id' => null]);

        for ($i = 1; $i <= 3; $i++) {
            $this->asOwner()->postJson('/api/v1/branches', $this->form(['name' => "Branch {$i}"]))->assertCreated();
        }
    }

    public function test_usage_reports_what_is_left(): void
    {
        $plan = SubscriptionPlan::create([
            'code' => 'growth', 'name' => 'Growth',
            'max_branches' => 5, 'max_users' => null, 'max_monthly_invoices' => 5000, 'monthly_price' => 45,
        ]);
        $this->merchant->update(['subscription_plan_id' => $plan->id]);

        Branch::factory()->for($this->merchant)->create();

        $this->asOwner()->getJson('/api/v1/branches/usage')
            ->assertOk()
            ->assertJsonPath('branches.used', 1)
            ->assertJsonPath('branches.max', 5)
            // Null means unlimited, and has to survive the trip as null.
            ->assertJsonPath('users.max', null)
            ->assertJsonPath('plan', 'Growth');
    }

    public function test_changes_are_written_to_the_audit_log(): void
    {
        Branch::factory()->for($this->merchant)->create(['name' => 'Other Branch']);

        $id = $this->asOwner()->postJson('/api/v1/branches', $this->form())->json('data.id');
        $this->asOwner()->putJson("/api/v1/branches/{$id}", $this->form(['city' => 'Aleppo']))->assertOk();
        $this->asOwner()->postJson("/api/v1/branches/{$id}/disable")->assertOk();

        foreach (['branch.created', 'branch.updated', 'branch.disabled'] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->count(), $action);
        }
    }
}
