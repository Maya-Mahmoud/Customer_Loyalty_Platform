<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\NewMerchantSubmissionMail;
use App\Mail\VerificationCodeMail;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeSmsGateway;
use Tests\TestCase;

/**
 * BP-01 steps 1 to 4 (BRD 8.1), plus FR-MER-01 and FR-MER-03.
 *
 * Only the email address is verified; see MerchantRegistrationService for why the
 * phone half of FR-MER-02 was dropped.
 */
class MerchantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** Meets the policy of BRD FR-SEC-03. */
    private const PASSWORD = 'Waha-Market-2026';

    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Bound so an accidental SMS send would be visible rather than silent.
        $this->sms = new FakeSmsGateway();
        $this->app->instance(SmsGateway::class, $this->sms);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Al Waha Market',
            'trade_name' => 'Al Waha',
            'commercial_register' => 'CR-55667788',
            'owner_name' => 'Sami Haddad',
            'email' => 'owner@alwaha.test',
            'phone' => '0991234567',
            'city' => 'Damascus',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accepts_terms' => true,
            'accepts_data_processing' => true,
            ...$overrides,
        ];
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/registration', $this->form($overrides));
    }

    private function sentCode(): string
    {
        $code = null;

        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    private function verify(?string $code = null, string $email = 'owner@alwaha.test'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/registration/verify', [
            'email' => $email,
            'code' => $code ?? $this->sentCode(),
        ]);
    }

    public function test_registration_creates_an_inert_pending_account(): void
    {
        $this->register()
            ->assertCreated()
            ->assertJsonPath('email', 'owner@alwaha.test')
            ->assertJsonPath('expires_in_minutes', (int) config('verification.ttl_minutes'));

        $merchant = Merchant::where('email', 'owner@alwaha.test')->firstOrFail();

        $this->assertSame(MerchantStatus::Pending, $merchant->status);
        $this->assertNull($merchant->email_verified_at);
        // Not in the review queue until the code is confirmed.
        $this->assertNull($merchant->submitted_at);

        /*
         * An amendment to BRD FR-MER-05, which names USD. The shops this platform
         * sells to price in Syrian pounds, and a default nobody wants is a setting
         * every owner has to find and change before their first sale — the one who
         * misses it prices their whole programme in a currency they never use.
         */
        $this->assertSame('SYP', $merchant->currency);
    }

    public function test_the_owner_keeps_the_password_they_chose_but_cannot_use_it_yet(): void
    {
        $this->register();

        $owner = User::withoutGlobalScopes()->where('email', 'owner@alwaha.test')->firstOrFail();

        $this->assertSame(UserRole::MerchantOwner, $owner->role);
        $this->assertSame(UserStatus::Active, $owner->status);
        $this->assertNull($owner->branch_id);
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $owner->password));

        // The user is fine; it is the merchant that is not approved yet, so the
        // account grants nothing until a supervisor activates it.
        $this->assertFalse($owner->canSignIn());
    }

    public function test_a_registered_owner_cannot_sign_in_before_activation(): void
    {
        $this->register();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@alwaha.test',
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_weak_password_is_refused(): void
    {
        // BRD FR-SEC-03 applies here exactly as it does to an invitation.
        foreach (['short', 'alllowercase123', 'NoDigitsAtAllHere'] as $weak) {
            $this->register(['password' => $weak, 'password_confirmation' => $weak])
                ->assertStatus(422)
                ->assertJsonValidationErrors('password');
        }
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $this->register(['password_confirmation' => 'Something-Else-2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_a_single_code_goes_to_the_email_and_no_sms_is_sent(): void
    {
        $this->register();

        Mail::assertSent(VerificationCodeMail::class, 1);

        // Registration must not spend the metered channel (BRD 5.4, RSK-05).
        $this->assertSame([], $this->sms->messages);
        $this->assertSame(1, VerificationCode::where('channel', 'email')->count());
        $this->assertSame(0, VerificationCode::where('channel', 'sms')->count());
    }

    public function test_confirming_the_code_puts_the_request_in_the_review_queue(): void
    {
        $this->register();

        $this->verify()->assertOk()->assertJsonPath('status', 'pending');

        $merchant = Merchant::where('email', 'owner@alwaha.test')->firstOrFail();

        $this->assertNotNull($merchant->email_verified_at);
        $this->assertNotNull($merchant->submitted_at);

        // The owner's phone is captured but never proven, and must stay that way
        // so the supervisor can see it on the review screen.
        $this->assertNull($merchant->phone_verified_at);

        // No supervisor exists in this case, so there is nobody to notify.
        Mail::assertNotSent(NewMerchantSubmissionMail::class);
    }

    public function test_the_platform_supervisor_is_notified_once_verified(): void
    {
        User::factory()->platformAdmin()->create(['email' => 'admin@platform.test']);

        $this->register();
        $this->verify()->assertOk();

        Mail::assertSent(
            NewMerchantSubmissionMail::class,
            fn (NewMerchantSubmissionMail $mail) => $mail->hasTo('admin@platform.test')
        );
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $this->register();

        $this->verify('000000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $record = VerificationCode::latest('id')->firstOrFail();

        $this->assertSame(1, $record->attempts);
        $this->assertFalse($record->isConsumed());
    }

    public function test_the_code_is_spent_once_it_works(): void
    {
        $this->register();
        $code = $this->sentCode();

        $this->verify($code)->assertOk();
        $submittedAt = Merchant::where('email', 'owner@alwaha.test')->value('submitted_at');

        // The merchant stays Pending until a supervisor activates it, so a replay
        // is stopped by the spent code rather than by the status.
        $this->verify($code)->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertEquals(
            $submittedAt,
            Merchant::where('email', 'owner@alwaha.test')->value('submitted_at'),
        );
    }

    public function test_codes_run_out_of_attempts(): void
    {
        $this->register();
        $max = (int) config('verification.max_attempts');

        for ($attempt = 0; $attempt < $max; $attempt++) {
            $this->verify('000000')->assertStatus(422);
        }

        // Even the correct code no longer works once the budget is spent.
        $this->verify()->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_an_in_use_commercial_register_is_refused(): void
    {
        Merchant::factory()->create(['commercial_register' => 'CR-55667788']);

        $this->register()
            ->assertStatus(422)
            ->assertJsonValidationErrors('commercial_register');
    }

    public function test_an_in_use_email_address_is_refused(): void
    {
        Merchant::factory()->create(['email' => 'owner@alwaha.test']);

        $this->register(['commercial_register' => 'CR-99887766'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_rejected_applicant_may_correct_and_apply_again(): void
    {
        // BRD 8.1 explicitly allows re-submission after a rejection.
        $rejected = Merchant::factory()->rejected()->create([
            'commercial_register' => 'CR-55667788',
            'email' => 'owner@alwaha.test',
            'name' => 'Old Name',
        ]);

        $this->register(['name' => 'Corrected Name'])->assertCreated();

        $rejected->refresh();

        $this->assertSame('Corrected Name', $rejected->name);
        $this->assertSame(MerchantStatus::Pending, $rejected->status);
        $this->assertNull($rejected->status_reason);
        $this->assertNull($rejected->email_verified_at);
        $this->assertSame(1, Merchant::where('commercial_register', 'CR-55667788')->count());
    }

    public function test_both_agreements_are_mandatory(): void
    {
        $this->register(['accepts_terms' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accepts_terms');

        $this->register(['accepts_data_processing' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accepts_data_processing');
    }

    public function test_the_phone_number_is_normalised_before_storage(): void
    {
        // BRD BR-002 makes the mobile number an identifier, so spacing must not
        // create two different values.
        $this->register(['phone' => '0991 234-567']);

        $this->assertDatabaseHas('merchants', [
            'email' => 'owner@alwaha.test',
            'phone' => '0991234567',
        ]);
    }

    public function test_resending_too_soon_is_refused(): void
    {
        $this->register();

        $this->postJson('/api/v1/registration/resend', ['email' => 'owner@alwaha.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_resending_after_the_cooldown_invalidates_the_previous_code(): void
    {
        $this->register();
        $firstCode = $this->sentCode();

        $this->travel((int) config('verification.resend_cooldown_seconds') + 5)->seconds();

        $this->postJson('/api/v1/registration/resend', ['email' => 'owner@alwaha.test'])
            ->assertOk();

        $this->verify($firstCode)->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_resending_for_an_unknown_email_is_refused(): void
    {
        $this->postJson('/api/v1/registration/resend', ['email' => 'nobody@nowhere.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_the_submission_is_written_to_the_audit_log(): void
    {
        $this->register();

        $this->assertSame(1, AuditLog::where('action', 'merchant.registration_submitted')->count());
    }

    // -----------------------------------------------------------------
    // What happens when the mail server is down
    // -----------------------------------------------------------------

    /** Makes every send throw the way an unreachable or misconfigured SMTP does. */
    private function breakMailer(string $message = 'SMTP is unreachable'): void
    {
        Mail::shouldReceive('to')->andThrow(
            new \Symfony\Component\Mailer\Exception\TransportException($message)
        );
    }

    /**
     * Puts a working fake back. Mockery has replaced the manager instance, so the
     * container has to forget it before Mail::fake() can build a real one again.
     */
    private function repairMailer(): void
    {
        Mail::clearResolvedInstances();
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');

        Mail::fake();
    }

    public function test_a_failed_send_leaves_no_half_registration_behind(): void
    {
        $this->breakMailer();

        $this->register()->assertStatus(503);

        /*
         * Nothing may survive. A stored merchant would take the commercial
         * register and the email, so the applicant could never retry — they would
         * be told they are already registered, with no code to verify with.
         */
        $this->assertDatabaseCount('merchants', 0);
        $this->assertDatabaseCount('verification_codes', 0);
        $this->assertSame(0, User::withoutGlobalScopes()->count());
    }

    public function test_the_applicant_can_retry_once_mail_recovers(): void
    {
        $this->breakMailer();
        $this->register()->assertStatus(503);

        $this->repairMailer();
        $this->register()->assertCreated();

        $this->assertDatabaseHas('merchants', ['commercial_register' => 'CR-55667788']);
        Mail::assertSent(VerificationCodeMail::class, 1);
    }

    public function test_a_mail_failure_never_leaks_server_details(): void
    {
        // The driver's own message names the SMTP host, the account and the
        // provider's reply. Registration is public, so none of that may surface
        // even with APP_DEBUG on.
        config(['app.debug' => true]);

        $this->breakMailer('Failed to authenticate on SMTP server with username "admin@example.com"');

        $response = $this->register()->assertStatus(503);

        $this->assertStringNotContainsString('SMTP', $response->getContent());
        $this->assertStringNotContainsString('admin@example.com', $response->getContent());
        $this->assertStringNotContainsString('authenticate', $response->getContent());
    }

    public function test_a_failed_resend_keeps_the_previous_code_working(): void
    {
        $this->register();
        $existingCode = $this->sentCode();

        $this->travel((int) config('verification.resend_cooldown_seconds') + 5)->seconds();

        $this->breakMailer();

        $this->postJson('/api/v1/registration/resend', ['email' => 'owner@alwaha.test'])
            ->assertStatus(503);

        // The old code must not have been retired by a resend that never landed,
        // otherwise a mail hiccup would lock the applicant out of their own
        // registration until the resend cooldown expired again.
        $this->verify($existingCode)->assertOk();
    }
}
