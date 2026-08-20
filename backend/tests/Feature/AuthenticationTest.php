<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sign-in rules of BRD 7.1, FR-ADM-03 and FR-SEC-02.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email, string $password = 'password'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_an_active_user_receives_a_token_and_their_profile(): void
    {
        $merchant = Merchant::factory()->create();
        $branch = Branch::factory()->for($merchant)->create();
        $rep = User::factory()->salesRep($branch)->create(['email' => 'rep@store.test']);

        $response = $this->login('rep@store.test');

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'role', 'permissions', 'merchant', 'branch']])
            ->assertJsonPath('user.id', $rep->id)
            ->assertJsonPath('user.role', 'sales_rep')
            ->assertJsonPath('user.merchant.id', $merchant->id);

        $this->assertNotNull($rep->fresh()->last_login_at);
    }

    public function test_a_wrong_password_is_rejected_without_revealing_the_account(): void
    {
        $merchant = Merchant::factory()->create();
        User::factory()->owner($merchant->id)->create(['email' => 'owner@store.test']);

        $wrongPassword = $this->login('owner@store.test', 'not-the-password');
        $unknownEmail = $this->login('nobody@store.test');

        $wrongPassword->assertStatus(422)->assertJsonValidationErrors('email');
        $unknownEmail->assertStatus(422)->assertJsonValidationErrors('email');

        // Both paths answer identically, so the endpoint cannot enumerate users.
        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownEmail->json('errors.email'),
        );
    }

    public function test_a_user_of_a_suspended_merchant_cannot_sign_in(): void
    {
        $merchant = Merchant::factory()->suspended()->create();
        User::factory()->owner($merchant->id)->create(['email' => 'owner@suspended.test']);

        $this->login('owner@suspended.test')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_disabled_user_cannot_sign_in(): void
    {
        $merchant = Merchant::factory()->create();
        User::factory()->owner($merchant->id)->disabled()->create(['email' => 'gone@store.test']);

        $this->login('gone@store.test')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_an_invited_user_cannot_sign_in_before_setting_a_password(): void
    {
        $merchant = Merchant::factory()->create();
        User::factory()->owner($merchant->id)->invited()->create(['email' => 'invited@store.test']);

        $this->login('invited@store.test')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_the_platform_admin_signs_in_without_a_merchant(): void
    {
        User::factory()->platformAdmin()->create(['email' => 'admin@platform.test']);

        $this->login('admin@platform.test')
            ->assertOk()
            ->assertJsonPath('user.role', 'platform_admin')
            ->assertJsonPath('user.merchant_id', null);
    }

    public function test_the_profile_endpoint_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_permissions_returned_match_the_role(): void
    {
        $merchant = Merchant::factory()->create();
        $branch = Branch::factory()->for($merchant)->create();
        $rep = User::factory()->salesRep($branch)->create();

        $this->actingAs($rep, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.permissions', [
                'customers.register',
                'invoices.create',
                'customers.lookup',
                // An amendment to BRD 7.2; the reasoning lives on
                // Permission::AcceptVoucher and in the matrix test.
                'vouchers.accept',
            ]);
    }

    public function test_suspending_a_merchant_locks_out_an_already_issued_token(): void
    {
        $merchant = Merchant::factory()->create();
        $branch = Branch::factory()->for($merchant)->create();
        $rep = User::factory()->salesRep($branch)->create();

        $this->actingAs($rep, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

        $merchant->update(['status' => \App\Enums\MerchantStatus::Suspended]);

        $this->actingAs($rep->fresh(), 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403);
    }

    public function test_sign_in_and_sign_out_are_written_to_the_audit_log(): void
    {
        $merchant = Merchant::factory()->create();
        $branch = Branch::factory()->for($merchant)->create();
        User::factory()->salesRep($branch)->create(['email' => 'rep@store.test']);

        $token = $this->login('rep@store.test')->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'auth.login')->count());
        $this->assertSame(1, AuditLog::where('action', 'auth.logout')->count());
    }

    public function test_a_failed_sign_in_is_recorded(): void
    {
        $this->login('ghost@store.test')->assertStatus(422);

        $this->assertSame(1, AuditLog::where('action', 'auth.login_failed')->count());
    }
}
