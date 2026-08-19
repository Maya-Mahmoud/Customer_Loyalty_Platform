<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Recovery for a forgotten password, by emailed code.
 *
 * A code rather than a link, matching registration: the whole recovery happens on
 * one screen, and it still works when the mailbox is on a phone while the browser
 * is on a laptop. A generated password is never sent — one mailed in clear text
 * stays readable in the mailbox forever.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Jadeed-Marour-2026';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function activeOwner(): User
    {
        $merchant = Merchant::factory()->create();

        return User::factory()->owner($merchant->id)->create(['email' => 'owner@store.test']);
    }

    private function forgot(string $email = 'owner@store.test'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/password/forgot', ['email' => $email]);
    }

    private function sentCode(): string
    {
        $code = null;

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    private function reset(?string $code = null, string $password = self::NEW_PASSWORD): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'owner@store.test',
            'code' => $code ?? $this->sentCode(),
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_a_code_is_emailed_and_no_link_is_sent(): void
    {
        $this->activeOwner();

        $this->forgot()
            ->assertOk()
            ->assertJsonPath('expires_in_minutes', (int) config('verification.ttl_minutes'));

        Mail::assertSent(PasswordResetMail::class, 1);

        $this->assertSame(1, VerificationCode::where('purpose', 'password_reset')->count());
    }

    public function test_the_code_lets_the_user_choose_a_new_password_in_one_step(): void
    {
        $owner = $this->activeOwner();
        $oldHash = $owner->password;

        $this->forgot();

        $response = $this->reset()->assertOk();

        $owner->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $owner->password));
        $this->assertNotSame($oldHash, $owner->password);

        // Signed straight in: they just proved control of the mailbox, so asking
        // for the password they only just chose would add nothing.
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $owner->id);
    }

    public function test_the_new_password_works_at_the_login_screen(): void
    {
        $this->activeOwner();
        $this->forgot();
        $this->reset()->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@store.test',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    public function test_the_old_password_stops_working(): void
    {
        $this->activeOwner();
        $this->forgot();
        $this->reset()->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@store.test',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_existing_sessions_are_revoked(): void
    {
        $owner = $this->activeOwner();
        $owner->createToken('web');

        $this->assertSame(1, $owner->tokens()->count());

        $this->forgot();
        $this->reset()->assertOk();

        // A reset is often prompted by a suspected compromise; leaving the old
        // sessions alive would defeat the point. The one issued by the reset itself
        // is the only survivor.
        $this->assertSame(1, $owner->fresh()->tokens()->count());
    }

    public function test_requesting_a_code_does_not_lock_the_user_out_meanwhile(): void
    {
        $owner = $this->activeOwner();

        $this->forgot()->assertOk();

        $owner->refresh();

        // An abandoned request must leave the account exactly as it was.
        $this->assertSame(UserStatus::Active, $owner->status);
        $this->assertTrue($owner->canSignIn());
        $this->assertTrue(Hash::check('password', $owner->password));
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $this->activeOwner();
        $this->forgot();

        $this->reset('000000')->assertStatus(422)->assertJsonValidationErrors('code');

        $record = VerificationCode::where('purpose', 'password_reset')->latest('id')->firstOrFail();

        $this->assertSame(1, $record->attempts);
        $this->assertFalse($record->isConsumed());
    }

    public function test_a_rejected_password_does_not_burn_the_code(): void
    {
        $this->activeOwner();
        $this->forgot();
        $code = $this->sentCode();

        // BRD FR-SEC-03 rejects this, but a policy failure must not cost the user
        // a fresh email — the same lesson as the registration codes.
        $this->reset($code, 'weak')->assertStatus(422)->assertJsonValidationErrors('password');

        $this->reset($code)->assertOk();
    }

    public function test_the_code_is_spent_once_it_works(): void
    {
        $this->activeOwner();
        $this->forgot();
        $code = $this->sentCode();

        $this->reset($code)->assertOk();
        $this->reset($code, 'Third-Attempt-2026')->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->activeOwner();
        $this->forgot();
        $code = $this->sentCode();

        $this->travel((int) config('verification.ttl_minutes') + 1)->minutes();

        $this->reset($code)->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        $this->activeOwner();

        $known = $this->forgot()->assertOk();
        $unknown = $this->forgot('nobody@nowhere.test')->assertOk();

        // Identical, so this endpoint cannot be used to discover who is registered.
        $this->assertSame($known->json('message'), $unknown->json('message'));
        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_resetting_an_unknown_address_fails_the_way_a_wrong_code_does(): void
    {
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'nobody@nowhere.test',
            'code' => '123456',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_no_code_goes_to_an_account_that_could_not_use_it(): void
    {
        $suspended = Merchant::factory()->suspended()->create();
        User::factory()->owner($suspended->id)->create(['email' => 'owner@suspended.test']);

        $active = Merchant::factory()->create();
        User::factory()->owner($active->id)->disabled()->create(['email' => 'gone@store.test']);

        $this->forgot('owner@suspended.test')->assertOk();
        $this->forgot('gone@store.test')->assertOk();

        Mail::assertNotSent(PasswordResetMail::class);
    }

    public function test_the_confirmation_must_match(): void
    {
        $this->activeOwner();
        $this->forgot();

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'owner@store.test',
            'code' => $this->sentCode(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'Something-Else-2026',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_both_ends_are_written_to_the_audit_log(): void
    {
        $this->activeOwner();

        $this->forgot();
        $this->reset()->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset_requested')->count());
        $this->assertSame(1, AuditLog::where('action', 'auth.password_reset_completed')->count());
    }

    public function test_the_reset_email_shows_a_code_and_carries_no_link_or_password(): void
    {
        $owner = $this->activeOwner();

        $html = (new PasswordResetMail($owner, '654321', 10))->render();

        $this->assertStringContainsString('654321', $html);
        $this->assertStringNotContainsString('set-password', $html);
        $this->assertStringNotContainsString($owner->password, $html);
    }
}
