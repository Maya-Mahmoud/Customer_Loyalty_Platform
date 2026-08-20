<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Route model binding must resolve inside the signed-in merchant.
 *
 * This closes a real hole. SubstituteBindings ships inside the `api` middleware
 * group, which runs before route middleware — so with ResolveTenant as route
 * middleware, `{user}` and `{branch}` were resolved while MerchantScope was still
 * inactive. Any id on the platform was reachable, including the platform
 * supervisor, who belongs to no merchant and therefore matches no scope.
 *
 * Every case here resets the tenant context first. Without that, a second request
 * in the same test inherits the context left by the first one and the check passes
 * for the wrong reason — which is exactly how this went unnoticed.
 */
class RouteBindingIsolationTest extends TestCase
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

    /**
     * Reproduces a fresh process: no merchant is in scope when the request
     * arrives, which is the state that made the hole reachable.
     */
    private function asOwnerWithColdContext(): self
    {
        app(TenantContext::class)->forget();

        $this->actingAs($this->owner, 'sanctum');

        return $this;
    }

    public function test_the_platform_supervisor_cannot_be_reached_through_a_staff_route(): void
    {
        $supervisor = User::factory()->platformAdmin()->create();

        // The supervisor has no merchant_id, so no merchant filter can ever match
        // them — which is precisely why an unscoped lookup handed them over.
        $this->asOwnerWithColdContext()
            ->putJson("/api/v1/staff/{$supervisor->id}", ['role' => 'branch_manager'])
            ->assertStatus(404);

        $this->asOwnerWithColdContext()
            ->postJson("/api/v1/staff/{$supervisor->id}/disable")
            ->assertStatus(404);

        $supervisor->refresh();

        $this->assertSame(UserRole::PlatformAdmin, $supervisor->role);
        $this->assertSame(UserStatus::Active, $supervisor->status);
        $this->assertNull($supervisor->merchant_id);
    }

    public function test_a_user_of_another_merchant_cannot_be_reached(): void
    {
        $other = Merchant::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $foreign = User::factory()->salesRep($otherBranch)->create(['name' => 'Untouched']);

        $this->asOwnerWithColdContext()
            ->putJson("/api/v1/staff/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->asOwnerWithColdContext()
            ->postJson("/api/v1/staff/{$foreign->id}/disable")
            ->assertStatus(404);

        $this->assertSame('Untouched', $foreign->fresh()->name);
        $this->assertSame(UserStatus::Active, $foreign->fresh()->status);
    }

    public function test_a_branch_of_another_merchant_cannot_be_reached(): void
    {
        $other = Merchant::factory()->create();
        $foreign = Branch::factory()->for($other)->create(['name' => 'Untouched', 'city' => 'Homs']);

        $this->asOwnerWithColdContext()
            ->putJson("/api/v1/branches/{$foreign->id}", ['name' => 'Hijacked', 'city' => 'Hama'])
            ->assertStatus(404);

        $this->asOwnerWithColdContext()
            ->postJson("/api/v1/branches/{$foreign->id}/disable")
            ->assertStatus(404);

        $foreign->refresh();

        $this->assertSame('Untouched', $foreign->name);
        $this->assertTrue($foreign->is_active);
    }

    public function test_a_merchants_own_records_still_resolve(): void
    {
        // The fix must not over-correct: the owner's own ids have to keep working
        // on a cold context, which is every real first request.
        $branch = Branch::factory()->for($this->merchant)->create();
        Branch::factory()->for($this->merchant)->create(['name' => 'Second Branch']);
        $rep = User::factory()->salesRep($branch)->create();

        $this->asOwnerWithColdContext()
            ->putJson("/api/v1/branches/{$branch->id}", ['name' => 'Renamed', 'city' => 'Aleppo'])
            ->assertOk();

        $this->asOwnerWithColdContext()
            ->putJson("/api/v1/staff/{$rep->id}", ['name' => 'Renamed Rep'])
            ->assertOk();
    }

    public function test_the_supervisor_console_still_reaches_every_merchant(): void
    {
        // The supervisor belongs to no merchant, so no scope applies and the whole
        // platform stays visible to them (BRD 7.1).
        $supervisor = User::factory()->platformAdmin()->create();
        $other = Merchant::factory()->create();

        app(TenantContext::class)->forget();

        $this->actingAs($supervisor, 'sanctum')
            ->getJson("/api/v1/admin/merchants/{$other->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $other->id);
    }
}
