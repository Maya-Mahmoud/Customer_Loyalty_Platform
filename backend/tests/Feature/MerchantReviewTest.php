<?php

namespace Tests\Feature;

use App\Enums\MerchantStatus;
use App\Enums\UserStatus;
use App\Mail\MerchantDecisionMail;
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
 * BP-01 steps 5 to 7 (BRD 8.1) and the supervisor console of BRD 9.1.
 */
class MerchantReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->supervisor = User::factory()->platformAdmin()->create();
    }

    private function asSupervisor(): self
    {
        $this->actingAs($this->supervisor, 'sanctum');

        return $this;
    }

    /**
     * Creates a verified pending merchant together with its owner, the state the
     * registration flow leaves behind: the owner already chose a password, and it
     * is the merchant's status that keeps them out.
     */
    private function awaitingReview(): Merchant
    {
        $merchant = Merchant::factory()->awaitingReview()->create();

        User::factory()->owner($merchant->id)->create([
            'email' => $merchant->email,
        ]);

        return $merchant;
    }

    /**
     * An owner with no password — a staff-style account, or a record left over
     * from before owners chose their password at registration.
     */
    private function ownerWithoutPassword(Merchant $merchant): User
    {
        return User::factory()->owner($merchant->id)->invited()->create([
            'email' => $merchant->email,
        ]);
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_only_the_platform_supervisor_reaches_the_console(): void
    {
        $merchant = Merchant::factory()->create();
        $owner = User::factory()->owner($merchant->id)->create();
        $branch = Branch::factory()->for($merchant)->create();
        $rep = User::factory()->salesRep($branch)->create();

        // By the matrix of BRD 7.2 nobody but the supervisor holds
        // merchants.manage_status.
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/merchants')->assertStatus(403);
        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/admin/merchants')->assertStatus(403);
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Because'])
            ->assertStatus(403);
    }

    public function test_the_console_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/merchants')->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // The queue (FR-ADM-01)
    // -----------------------------------------------------------------

    public function test_the_queue_lists_every_request_with_its_status(): void
    {
        Merchant::factory()->create();
        Merchant::factory()->suspended()->create();
        $pending = $this->awaitingReview();

        $response = $this->asSupervisor()->getJson('/api/v1/admin/merchants')->assertOk();

        $this->assertSame(3, $response->json('meta.total'));
        // Unreviewed requests come first, because acting on them is the point.
        $this->assertSame($pending->id, $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.is_verified'));
    }

    public function test_the_queue_can_be_filtered_and_searched(): void
    {
        Merchant::factory()->create(['name' => 'Active Store']);
        $pending = $this->awaitingReview();

        $byStatus = $this->asSupervisor()->getJson('/api/v1/admin/merchants?status=pending')->assertOk();
        $this->assertSame(1, $byStatus->json('meta.total'));
        $this->assertSame($pending->id, $byStatus->json('data.0.id'));

        $bySearch = $this->asSupervisor()->getJson('/api/v1/admin/merchants?search=Active')->assertOk();
        $this->assertSame(1, $bySearch->json('meta.total'));
        $this->assertSame('Active Store', $bySearch->json('data.0.name'));
    }

    public function test_stats_report_the_queue_length(): void
    {
        $this->awaitingReview();
        Merchant::factory()->create();
        Merchant::factory()->rejected()->create();

        $this->asSupervisor()->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->assertJsonPath('awaiting_review', 1)
            ->assertJsonPath('merchants.active', 1)
            ->assertJsonPath('merchants.rejected', 1)
            ->assertJsonPath('total', 3);
    }

    public function test_reading_a_merchant_record_is_itself_logged(): void
    {
        $merchant = Merchant::factory()->create();

        $this->asSupervisor()->getJson("/api/v1/admin/merchants/{$merchant->id}")->assertOk();

        // BRD section 16 requires support access to customer-bearing accounts to
        // leave a trace.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'merchant.viewed_by_platform',
            'user_id' => $this->supervisor->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Activation (step 6)
    // -----------------------------------------------------------------

    public function test_activation_lets_the_owner_in_with_the_password_they_chose(): void
    {
        $merchant = $this->awaitingReview();
        $owner = User::withoutGlobalScopes()->where('email', $merchant->email)->firstOrFail();

        $this->assertFalse($owner->canSignIn());

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $merchant->refresh();

        $this->assertSame(MerchantStatus::Active, $merchant->status);
        $this->assertNotNull($merchant->activated_at);
        $this->assertSame($this->supervisor->id, $merchant->reviewed_by);
        $this->assertNotNull($merchant->reviewed_at);

        // Nothing more is asked of the owner: their mailbox was already proven by
        // the registration code, and they picked the password on that same form.
        Mail::assertNotSent(UserInvitationMail::class);
        Mail::assertSent(
            MerchantDecisionMail::class,
            fn (MerchantDecisionMail $mail) => $mail->status === MerchantStatus::Active
        );

        $this->assertTrue($owner->fresh()->canSignIn());
    }

    public function test_an_unverified_request_cannot_be_approved(): void
    {
        // BRD FR-MER-02: the email and phone must be proven first.
        $merchant = Merchant::factory()->pending()->create();

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/activate")
            ->assertStatus(409);

        $this->assertSame(MerchantStatus::Pending, $merchant->fresh()->status);
    }

    public function test_an_already_active_merchant_cannot_be_activated_again(): void
    {
        $merchant = Merchant::factory()->create(['activated_at' => now()->subMonths(3)]);
        $originalActivation = $merchant->activated_at;

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/activate")
            ->assertStatus(409);

        // A double click must not rewrite the review trail.
        $this->assertEquals($originalActivation, $merchant->fresh()->activated_at);
    }

    // -----------------------------------------------------------------
    // Rejection (step 5, alternative path)
    // -----------------------------------------------------------------

    public function test_rejection_demands_a_written_reason(): void
    {
        $merchant = $this->awaitingReview();

        // BRD FR-ADM-02 makes the reason mandatory.
        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(MerchantStatus::Pending, $merchant->fresh()->status);
    }

    public function test_rejection_records_the_reason_and_notifies_the_applicant(): void
    {
        $merchant = $this->awaitingReview();

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/reject", [
                'reason' => 'The commercial register could not be confirmed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $merchant->refresh();

        $this->assertSame(MerchantStatus::Rejected, $merchant->status);
        $this->assertSame('The commercial register could not be confirmed.', $merchant->status_reason);
        $this->assertSame($this->supervisor->id, $merchant->reviewed_by);

        Mail::assertSent(
            MerchantDecisionMail::class,
            fn (MerchantDecisionMail $mail) => $mail->status === MerchantStatus::Rejected
                && $mail->reason === 'The commercial register could not be confirmed.'
        );
    }

    public function test_an_active_merchant_cannot_be_rejected(): void
    {
        $merchant = Merchant::factory()->create();

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/reject", ['reason' => 'Changed my mind'])
            ->assertStatus(409);
    }

    // -----------------------------------------------------------------
    // Suspension (FR-ADM-02, FR-ADM-03, BR-020)
    // -----------------------------------------------------------------

    public function test_suspension_demands_a_written_reason(): void
    {
        $merchant = Merchant::factory()->create();

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/suspend", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Unpaid subscription'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.status_reason', 'Unpaid subscription');
    }

    public function test_suspension_revokes_tokens_already_in_circulation(): void
    {
        $merchant = Merchant::factory()->create();
        $branch = Branch::factory()->for($merchant)->create();
        $rep = User::factory()->salesRep($branch)->create();

        $token = $rep->createToken('web')->plainTextToken;
        $this->assertSame(1, $rep->tokens()->count());

        // Driven through the service rather than the endpoint: acting as the
        // supervisor would leave a resolved user on the guard, and the assertion
        // below would then pass for the wrong reason.
        app(\App\Services\MerchantStatusService::class)
            ->suspend($merchant, $this->supervisor, 'Unpaid subscription');

        $this->assertSame(0, $rep->fresh()->tokens()->count());

        // EnsureAccountIsActive would answer 403 on its own; revoking makes the
        // lockout immediate instead of waiting for the token to expire.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_suspension_surfaces_the_ninety_day_retention_floor(): void
    {
        $merchant = Merchant::factory()->create();

        $response = $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Unpaid subscription'])
            ->assertOk();

        // BRD BR-020: data must be kept for at least 90 days after suspension.
        $this->assertSame(
            now()->addDays(90)->toDateString(),
            $response->json('data.retention_floor'),
        );
    }

    public function test_suspension_deletes_no_data(): void
    {
        $merchant = Merchant::factory()->create();
        $branch = Branch::factory()->for($merchant)->create();
        $rep = User::factory()->salesRep($branch)->create();

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/suspend", ['reason' => 'Unpaid subscription'])
            ->assertOk();

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $rep->id, 'deleted_at' => null]);
    }

    public function test_a_suspended_merchant_can_be_restored(): void
    {
        $merchant = Merchant::factory()->suspended()->create(['activated_at' => now()->subYear()]);
        $firstActivation = $merchant->activated_at;

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_reason', null);

        // The original activation date survives, so account age stays true.
        $this->assertEquals($firstActivation, $merchant->fresh()->activated_at);
    }

    // -----------------------------------------------------------------
    // Subscription plan (FR-ADM-04)
    // -----------------------------------------------------------------

    public function test_a_subscription_plan_can_be_assigned(): void
    {
        $merchant = Merchant::factory()->create();
        $plan = SubscriptionPlan::create([
            'code' => 'growth',
            'name' => 'Growth',
            'max_branches' => 5,
            'max_users' => 15,
            'max_monthly_invoices' => 5000,
            'monthly_price' => 45,
        ]);

        $this->asSupervisor()
            ->putJson("/api/v1/admin/merchants/{$merchant->id}/subscription", [
                'subscription_plan_id' => $plan->id,
                'subscription_ends_at' => now()->addYear()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.subscription_plan.code', 'growth')
            // Null caps mean unlimited, so the numbers have to survive the trip.
            ->assertJsonPath('data.subscription_plan.max_branches', 5);
    }

    // -----------------------------------------------------------------
    // Audit trail (FR-SEC-02)
    // -----------------------------------------------------------------

    public function test_every_decision_is_written_to_the_audit_log(): void
    {
        $activated = $this->awaitingReview();
        $rejected = $this->awaitingReview();
        $suspended = Merchant::factory()->create();

        $this->asSupervisor()->postJson("/api/v1/admin/merchants/{$activated->id}/activate")->assertOk();
        $this->asSupervisor()->postJson("/api/v1/admin/merchants/{$rejected->id}/reject", ['reason' => 'Not eligible'])->assertOk();
        $this->asSupervisor()->postJson("/api/v1/admin/merchants/{$suspended->id}/suspend", ['reason' => 'Unpaid'])->assertOk();

        foreach (['merchant.activated', 'merchant.rejected', 'merchant.suspended'] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->count(), $action);
        }

        // The reason is captured in the entry, not only on the merchant row.
        $entry = AuditLog::where('action', 'merchant.rejected')->firstOrFail();
        $this->assertSame('Not eligible', $entry->after['status_reason']);
        $this->assertSame($this->supervisor->id, $entry->user_id);
    }

    public function test_a_rejected_owner_still_cannot_sign_in(): void
    {
        $merchant = $this->awaitingReview();

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/reject", ['reason' => 'Not eligible'])
            ->assertOk();

        $owner = User::withoutGlobalScopes()->where('email', $merchant->email)->firstOrFail();

        // The user account itself is fine and keeps the password chosen at
        // registration; it is the rejected merchant that shuts the door.
        $this->assertSame(UserStatus::Active, $owner->status);
        $this->assertFalse($owner->canSignIn());
    }

    // -----------------------------------------------------------------
    // Re-inviting the owner
    // -----------------------------------------------------------------

    public function test_an_owner_left_without_a_password_can_be_invited(): void
    {
        $merchant = Merchant::factory()->create();
        $owner = $this->ownerWithoutPassword($merchant);

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/resend-invitation")
            ->assertOk();

        Mail::assertSent(UserInvitationMail::class, 1);
        $this->assertNotNull($owner->fresh()->invitation_token);
    }

    public function test_the_invitation_link_is_never_returned_to_the_supervisor(): void
    {
        $merchant = Merchant::factory()->create();
        $owner = $this->ownerWithoutPassword($merchant);

        $response = $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/resend-invitation")
            ->assertOk();

        // Holding the token would let the supervisor set the owner's password and
        // take over the merchant, which is what BRD FR-ADM-06 exists to prevent.
        $this->assertStringNotContainsString((string) $owner->fresh()->invitation_token, $response->getContent());
        $this->assertStringNotContainsString('set-password', $response->getContent());
        $response->assertJsonMissingPath('data.owner.invitation_token');
    }

    public function test_the_console_reports_whether_the_owner_can_sign_in_yet(): void
    {
        $merchant = Merchant::factory()->create();
        $this->ownerWithoutPassword($merchant);

        // An account whose owner has no password is open but unusable, and
        // nothing else on the screen would show that.
        $this->asSupervisor()
            ->getJson("/api/v1/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->assertJsonPath('data.owner.status', 'invited')
            ->assertJsonPath('data.owner.has_password', false);
    }

    public function test_an_owner_who_already_has_a_password_cannot_be_reset_by_a_resend(): void
    {
        $merchant = Merchant::factory()->create();
        User::factory()->owner($merchant->id)->create(['email' => $merchant->email]);

        // Re-inviting would flip an active owner back to Invited and lock them out
        // of their own account, so it is refused outright. Forgetting a password
        // is the reset flow, not this one.
        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/resend-invitation")
            ->assertStatus(409);

        $owner = User::withoutGlobalScopes()->where('email', $merchant->email)->firstOrFail();

        $this->assertSame(UserStatus::Active, $owner->status);
        $this->assertTrue($owner->canSignIn());
    }

    public function test_an_unactivated_merchant_cannot_have_its_owner_invited(): void
    {
        $merchant = Merchant::factory()->awaitingReview()->create();
        $this->ownerWithoutPassword($merchant);

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/resend-invitation")
            ->assertStatus(409);

        Mail::assertNotSent(UserInvitationMail::class);
    }

    public function test_resending_is_written_to_the_audit_log(): void
    {
        $merchant = Merchant::factory()->create();
        $this->ownerWithoutPassword($merchant);

        $this->asSupervisor()
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/resend-invitation")
            ->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'merchant.owner_invitation_resent')->count());
    }

    public function test_only_the_supervisor_may_resend_an_invitation(): void
    {
        $merchant = Merchant::factory()->create();
        $owner = User::factory()->owner($merchant->id)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/resend-invitation")
            ->assertStatus(403);
    }
}
