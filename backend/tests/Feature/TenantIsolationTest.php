<?php

namespace Tests\Feature;

use App\Exceptions\CrossTenantWriteException;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers BRD FR-ADM-06 and the "deliberate cross-tenant access test" that BRD 20
 * lists as a release gate. Two merchants exist in every case, because isolation
 * cannot be proven with one.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $first;

    private Merchant $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = Merchant::factory()->create(['name' => 'First Store']);
        $this->second = Merchant::factory()->create(['name' => 'Second Store']);

        Branch::factory()->for($this->first)->create(['name' => 'First A']);
        Branch::factory()->for($this->first)->create(['name' => 'First B']);
        Branch::factory()->for($this->second)->create(['name' => 'Second A']);
    }

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_reads_only_return_rows_of_the_merchant_in_scope(): void
    {
        $this->tenant()->set($this->first->id);
        $this->assertSame(2, Branch::count());
        $this->assertEqualsCanonicalizing(['First A', 'First B'], Branch::pluck('name')->all());

        $this->tenant()->set($this->second->id);
        $this->assertSame(1, Branch::count());
        $this->assertSame(['Second A'], Branch::pluck('name')->all());
    }

    public function test_looking_up_another_merchants_row_by_id_finds_nothing(): void
    {
        $foreign = Branch::withoutGlobalScopes()->where('name', 'First A')->firstOrFail();

        $this->tenant()->set($this->second->id);

        $this->assertNull(Branch::find($foreign->id));
    }

    public function test_merchant_id_is_filled_from_the_context_on_create(): void
    {
        $this->tenant()->set($this->second->id);

        $branch = Branch::create(['name' => 'Autofilled', 'city' => 'Homs']);

        $this->assertSame($this->second->id, $branch->merchant_id);
    }

    public function test_writing_a_row_for_another_merchant_is_refused(): void
    {
        $this->tenant()->set($this->first->id);

        $this->expectException(CrossTenantWriteException::class);

        Branch::create([
            'merchant_id' => $this->second->id,
            'name' => 'Injected',
            'city' => 'Hama',
        ]);
    }

    public function test_moving_an_existing_row_to_another_merchant_is_refused(): void
    {
        $this->tenant()->set($this->first->id);
        $branch = Branch::where('name', 'First A')->firstOrFail();

        $this->expectException(CrossTenantWriteException::class);

        $branch->update(['merchant_id' => $this->second->id]);
    }

    public function test_users_are_isolated_between_merchants(): void
    {
        User::factory()->owner($this->first->id)->create();
        User::factory()->owner($this->first->id)->create();
        User::factory()->owner($this->second->id)->create();

        $this->tenant()->set($this->first->id);
        $this->assertSame(2, User::count());

        $this->tenant()->set($this->second->id);
        $this->assertSame(1, User::count());
    }

    public function test_platform_admin_context_is_not_scoped(): void
    {
        // A platform supervisor has no merchant, so no filter is applied — the
        // support access described in BRD 7.1.
        $this->tenant()->set(null);

        $this->assertSame(3, Branch::count());
    }

    public function test_scope_can_be_switched_temporarily_and_restored(): void
    {
        $this->tenant()->set($this->first->id);

        $countForSecond = $this->tenant()->for($this->second->id, fn () => Branch::count());

        $this->assertSame(1, $countForSecond);
        $this->assertSame($this->first->id, $this->tenant()->id());
        $this->assertSame(2, Branch::count());
    }
}
