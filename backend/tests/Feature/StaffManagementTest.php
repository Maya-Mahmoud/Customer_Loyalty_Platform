<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\UserInvitationMail;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Staff setup, BRD 8.2 step 2 and FR-BRN-02 to FR-BRN-06.
 *
 * Much of this covers refusals. A store can lock itself out of its own account by
 * removing the last owner or disabling itself, and nobody but the platform
 * supervisor could undo that — so each route out is closed deliberately.
 */
class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private User $owner;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->merchant = Merchant::factory()->create();
        $this->owner = User::factory()->owner($this->merchant->id)->create();
        $this->branch = Branch::factory()->for($this->merchant)->create();
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
            'name' => 'Sami Haddad',
            'email' => 'sami@store.test',
            'phone' => '0991234567',
            'role' => 'sales_rep',
            'branch_id' => $this->branch->id,
            ...$overrides,
        ];
    }

    // -----------------------------------------------------------------
    // Access (BRD 7.2)
    // -----------------------------------------------------------------

    public function test_only_the_owner_manages_staff(): void
    {
        $manager = User::factory()->branchManager($this->branch)->create();
        $rep = User::factory()->salesRep($this->branch)->create();

        foreach ([$manager, $rep] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/staff')->assertStatus(403);
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/staff', $this->form())->assertStatus(403);
        }
    }

    public function test_staff_of_another_merchant_are_invisible(): void
    {
        $other = Merchant::factory()->create();
        $otherBranch = Branch::factory()->for($other)->create();
        $foreign = User::factory()->salesRep($otherBranch)->create();

        $response = $this->asOwner()->getJson('/api/v1/staff')->assertOk();

        $emails = array_column($response->json('data'), 'email');
        $this->assertNotContains($foreign->email, $emails);

        $this->asOwner()->putJson("/api/v1/staff/{$foreign->id}", ['name' => 'Hijacked'])->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Creating (FR-BRN-02, FR-BRN-04)
    // -----------------------------------------------------------------

    public function test_a_user_is_created_and_invited_to_set_their_own_password(): void
    {
        $response = $this->asOwner()
            ->postJson('/api/v1/staff', $this->form())
            ->assertCreated()
            ->assertJsonPath('data.role', 'sales_rep')
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.has_password', false);

        $user = User::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        // No password is chosen on their behalf (BRD FR-BRN-04).
        $this->assertNull($user->password);
        $this->assertNotNull($user->invitation_token);
        $this->assertSame($this->merchant->id, $user->merchant_id);
        $this->assertFalse($user->canSignIn());

        Mail::assertSent(
            UserInvitationMail::class,
            fn (UserInvitationMail $mail) => $mail->hasTo('sami@store.test')
        );
    }

    public function test_the_invited_user_can_set_a_password_and_sign_in(): void
    {
        $this->asOwner()->postJson('/api/v1/staff', $this->form())->assertCreated();

        $token = null;
        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail) use (&$token) {
            $token = str($mail->invitationUrl)->afterLast('/')->toString();

            return true;
        });

        $this->postJson("/api/v1/invitations/{$token}", [
            'password' => 'Sami-Store-2026',
            'password_confirmation' => 'Sami-Store-2026',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'sami@store.test',
            'password' => 'Sami-Store-2026',
        ])->assertOk()->assertJsonPath('user.role', 'sales_rep');
    }

    public function test_a_branch_bound_role_needs_a_branch(): void
    {
        // BRD FR-BRN-03: managers and reps only ever see their own branch, so they
        // cannot exist without one.
        foreach (['sales_rep', 'branch_manager'] as $role) {
            $this->asOwner()
                ->postJson('/api/v1/staff', $this->form(['role' => $role, 'branch_id' => null]))
                ->assertStatus(409);
        }
    }

    public function test_an_owner_is_not_pinned_to_a_branch(): void
    {
        // An owner spans every branch (BRD 7.1), so any branch sent is ignored.
        $response = $this->asOwner()
            ->postJson('/api/v1/staff', $this->form([
                'role' => 'merchant_owner',
                'branch_id' => $this->branch->id,
            ]))
            ->assertCreated();

        $this->assertNull($response->json('data.branch_id'));
    }

    public function test_a_branch_of_another_merchant_cannot_be_assigned(): void
    {
        $other = Merchant::factory()->create();
        $foreignBranch = Branch::factory()->for($other)->create();

        $this->asOwner()
            ->postJson('/api/v1/staff', $this->form(['branch_id' => $foreignBranch->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_a_platform_supervisor_cannot_be_created_from_inside_a_merchant(): void
    {
        // Otherwise a store owner could grant themselves the whole platform.
        $this->asOwner()
            ->postJson('/api/v1/staff', $this->form(['role' => 'platform_admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $this->asOwner()->postJson('/api/v1/staff', $this->form())->assertCreated();

        $this->asOwner()->postJson('/api/v1/staff', $this->form(['name' => 'Someone Else']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_the_plan_cap_blocks_another_user(): void
    {
        $plan = SubscriptionPlan::create([
            'code' => 'starter', 'name' => 'Starter',
            'max_branches' => 1, 'max_users' => 1, 'max_monthly_invoices' => 500, 'monthly_price' => 15,
        ]);
        $this->merchant->update(['subscription_plan_id' => $plan->id]);

        // The owner already fills the single seat.
        $this->asOwner()->postJson('/api/v1/staff', $this->form())->assertStatus(409);
    }

    // -----------------------------------------------------------------
    // Editing and moving between branches (FR-BRN-06)
    // -----------------------------------------------------------------

    public function test_a_user_can_be_moved_to_another_branch(): void
    {
        $destination = Branch::factory()->for($this->merchant)->create(['name' => 'Aleppo Branch']);
        $rep = User::factory()->salesRep($this->branch)->create();

        $this->asOwner()
            ->putJson("/api/v1/staff/{$rep->id}", ['branch_id' => $destination->id])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $destination->id);
    }

    public function test_a_user_can_be_renamed_and_have_their_role_changed(): void
    {
        $rep = User::factory()->salesRep($this->branch)->create();

        $this->asOwner()
            ->putJson("/api/v1/staff/{$rep->id}", [
                'name' => 'Promoted Person',
                'role' => 'branch_manager',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Promoted Person')
            ->assertJsonPath('data.role', 'branch_manager')
            // Still branch bound, so the branch carries over rather than clearing.
            ->assertJsonPath('data.branch_id', $this->branch->id);
    }

    public function test_promoting_someone_to_owner_clears_their_branch(): void
    {
        $rep = User::factory()->salesRep($this->branch)->create();

        $this->asOwner()
            ->putJson("/api/v1/staff/{$rep->id}", ['role' => 'merchant_owner'])
            ->assertOk()
            ->assertJsonPath('data.branch_id', null);
    }

    // -----------------------------------------------------------------
    // Disabling, never deleting (FR-BRN-05)
    // -----------------------------------------------------------------

    public function test_disabling_a_user_keeps_the_account_and_revokes_their_sessions(): void
    {
        $rep = User::factory()->salesRep($this->branch)->create();
        $rep->createToken('web');

        $this->asOwner()
            ->postJson("/api/v1/staff/{$rep->id}/disable")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        // BRD FR-BRN-05: nothing is deleted, so the invoices they entered keep
        // pointing at them.
        $this->assertDatabaseHas('users', ['id' => $rep->id, 'deleted_at' => null]);
        $this->assertSame(0, $rep->fresh()->tokens()->count());
        $this->assertFalse($rep->fresh()->canSignIn());
    }

    public function test_re_enabling_returns_a_user_who_never_accepted_to_invited(): void
    {
        $id = $this->asOwner()->postJson('/api/v1/staff', $this->form())->json('data.id');

        $this->asOwner()->postJson("/api/v1/staff/{$id}/disable")->assertOk();

        // They still have no password, so Active would be a lie.
        $this->asOwner()
            ->postJson("/api/v1/staff/{$id}/enable")
            ->assertOk()
            ->assertJsonPath('data.status', 'invited');
    }

    public function test_re_enabling_returns_a_user_who_had_a_password_to_active(): void
    {
        $rep = User::factory()->salesRep($this->branch)->create();

        $this->asOwner()->postJson("/api/v1/staff/{$rep->id}/disable")->assertOk();

        $this->asOwner()
            ->postJson("/api/v1/staff/{$rep->id}/enable")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    // -----------------------------------------------------------------
    // Locking yourself out
    // -----------------------------------------------------------------

    public function test_you_cannot_disable_yourself(): void
    {
        $this->asOwner()
            ->postJson("/api/v1/staff/{$this->owner->id}/disable")
            ->assertStatus(409);

        $this->assertTrue($this->owner->fresh()->canSignIn());
    }

    public function test_you_cannot_change_your_own_role(): void
    {
        $this->asOwner()
            ->putJson("/api/v1/staff/{$this->owner->id}", ['role' => 'branch_manager'])
            ->assertStatus(409);

        $this->assertSame(UserRole::MerchantOwner, $this->owner->fresh()->role);
    }

    public function test_the_last_active_owner_cannot_be_demoted_or_disabled(): void
    {
        $second = User::factory()->owner($this->merchant->id)->create();

        // While a second owner exists, either is fair game.
        $this->asOwner()->putJson("/api/v1/staff/{$second->id}", ['role' => 'branch_manager', 'branch_id' => $this->branch->id])
            ->assertOk();

        // Now the signed-in owner is the only one left. Demoting them from another
        // account would leave the store unable to manage itself at all.
        $this->actingAs($second->fresh(), 'sanctum');
        $this->assertSame(UserRole::BranchManager, $second->fresh()->role);

        $this->asOwner()->postJson("/api/v1/staff/{$this->owner->id}/disable")->assertStatus(409);
    }

    public function test_a_second_owner_can_be_disabled(): void
    {
        $second = User::factory()->owner($this->merchant->id)->create();

        $this->asOwner()
            ->postJson("/api/v1/staff/{$second->id}/disable")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');
    }

    // -----------------------------------------------------------------
    // Re-inviting
    // -----------------------------------------------------------------

    public function test_a_user_who_never_accepted_can_be_invited_again(): void
    {
        $id = $this->asOwner()->postJson('/api/v1/staff', $this->form())->json('data.id');

        $this->asOwner()->postJson("/api/v1/staff/{$id}/resend-invitation")->assertOk();

        Mail::assertSent(UserInvitationMail::class, 2);
    }

    public function test_someone_with_a_password_is_not_re_invited(): void
    {
        $rep = User::factory()->salesRep($this->branch)->create();

        // It would flip them back to Invited and lock them out of a working
        // account; a forgotten password is the reset flow instead.
        $this->asOwner()
            ->postJson("/api/v1/staff/{$rep->id}/resend-invitation")
            ->assertStatus(409);

        $this->assertSame(UserStatus::Active, $rep->fresh()->status);
    }

    public function test_the_invitation_link_is_never_returned_to_the_owner(): void
    {
        $id = $this->asOwner()->postJson('/api/v1/staff', $this->form())->json('data.id');

        $response = $this->asOwner()->postJson("/api/v1/staff/{$id}/resend-invitation")->assertOk();

        $user = User::withoutGlobalScopes()->findOrFail($id);

        $this->assertStringNotContainsString((string) $user->invitation_token, $response->getContent());
        $this->assertStringNotContainsString('set-password', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------

    public function test_changes_are_written_to_the_audit_log(): void
    {
        $id = $this->asOwner()->postJson('/api/v1/staff', $this->form())->json('data.id');
        $this->asOwner()->putJson("/api/v1/staff/{$id}", ['name' => 'Renamed'])->assertOk();
        $this->asOwner()->postJson("/api/v1/staff/{$id}/disable")->assertOk();

        foreach (['user.created', 'user.updated', 'user.disabled'] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->count(), $action);
        }
    }
}
