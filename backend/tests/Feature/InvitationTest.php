<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * BRD FR-BRN-04: an invited user sets their own password. This is also how the
 * owner of a newly activated merchant gets in, instead of BRD 8.1 step 6's
 * literal "sign-in details are sent" — a mailed password would stay readable in
 * the inbox indefinitely.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Sahl-Marour-2026';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function invitedOwner(): array
    {
        $merchant = Merchant::factory()->create(['name' => 'Al Waha Market']);
        $owner = User::factory()->owner($merchant->id)->invited()->create();

        $token = app(InvitationService::class)->invite($owner);

        return [$owner->fresh(), $token];
    }

    public function test_the_link_reveals_who_it_belongs_to_before_the_form_is_filled(): void
    {
        [$owner, $token] = $this->invitedOwner();

        $this->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('email', $owner->email)
            ->assertJsonPath('role', 'merchant_owner')
            ->assertJsonPath('merchant_name', 'Al Waha Market');
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->getJson('/api/v1/invitations/'.str_repeat('a', 48))->assertStatus(404);
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        [$owner, $token] = $this->invitedOwner();

        // A leaked database must not yield working invitation links.
        $this->assertNotSame($token, $owner->invitation_token);
        $this->assertSame(hash('sha256', $token), $owner->invitation_token);
    }

    public function test_accepting_the_invitation_sets_the_password_and_signs_the_user_in(): void
    {
        [$owner, $token] = $this->invitedOwner();

        $response = $this->postJson("/api/v1/invitations/{$token}", [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'role', 'permissions']]);

        $owner->refresh();

        $this->assertSame(UserStatus::Active, $owner->status);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $owner->password));
        $this->assertTrue($owner->canSignIn());

        // The returned token works straight away, so there is no second login.
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $owner->id);
    }

    public function test_the_link_only_works_once(): void
    {
        [, $token] = $this->invitedOwner();

        $this->postJson("/api/v1/invitations/{$token}", [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertOk();

        $this->postJson("/api/v1/invitations/{$token}", [
            'password' => 'Another-Pass-2026',
            'password_confirmation' => 'Another-Pass-2026',
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_an_expired_link_is_refused(): void
    {
        [, $token] = $this->invitedOwner();

        $this->travel((int) config('clp.invitation_ttl_hours') + 1)->hours();

        $this->getJson("/api/v1/invitations/{$token}")->assertStatus(404);

        $this->postJson("/api/v1/invitations/{$token}", [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_a_weak_password_is_refused(): void
    {
        [, $token] = $this->invitedOwner();

        // BRD FR-SEC-03 requires a strong password policy.
        foreach (['short', 'alllowercase123', 'NoDigitsAtAllHere'] as $weak) {
            $this->postJson("/api/v1/invitations/{$token}", [
                'password' => $weak,
                'password_confirmation' => $weak,
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        [, $token] = $this->invitedOwner();

        $this->postJson("/api/v1/invitations/{$token}", [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => 'Something-Else-2026',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_issuing_a_new_invitation_invalidates_the_previous_link(): void
    {
        [$owner, $firstToken] = $this->invitedOwner();

        app(InvitationService::class)->invite($owner);

        $this->getJson("/api/v1/invitations/{$firstToken}")->assertStatus(404);
    }

    public function test_acceptance_is_written_to_the_audit_log(): void
    {
        [, $token] = $this->invitedOwner();

        $this->postJson("/api/v1/invitations/{$token}", [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'user.invitation_accepted')->count());
    }

    public function test_a_staff_invitation_resolves_across_merchants(): void
    {
        // The token lookup has to ignore the tenant scope, because the invitee is
        // not signed in and no merchant is in context yet.
        $merchant = Merchant::factory()->create();
        $branch = Branch::factory()->for($merchant)->create();
        $rep = User::factory()->salesRep($branch)->invited()->create();

        $token = app(InvitationService::class)->invite($rep);

        $this->getJson("/api/v1/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('role', 'sales_rep');
    }
}
