<?php

namespace Tests\Feature;

use App\Enums\MerchantStatus;
use App\Mail\NewMerchantSubmissionMail;
use App\Mail\VerificationCodeMail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Registration with the email verification of BRD FR-MER-02 switched off, which is
 * the shipped default (see config/clp.php).
 *
 * The reasoning is that the supervisor's approval, not the code, is what decides
 * whether an account exists: no registration grants any access until FR-ADM-02
 * activation. What these cases hold is that switching the step off removes a step
 * and nothing else — the account is still inert, the register and the email are
 * still taken, and the supervisor is still the one who decides.
 *
 * MerchantRegistrationTest covers the same flow with verification on.
 */
class MerchantRegistrationWithoutVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // The shipped default, stated rather than inherited so this class keeps
        // testing what it says it tests even if the default changes.
        config(['clp.require_email_verification' => false]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/registration', [
            'name' => 'Al Waha Market',
            'trade_name' => 'Al Waha',
            'commercial_register' => 'CR-55667788',
            'owner_name' => 'Sami Haddad',
            'email' => 'owner@alwaha.test',
            'phone' => '0991234567',
            'city' => 'Damascus',
            'password' => 'waha-2026',
            'password_confirmation' => 'waha-2026',
            'accepts_terms' => true,
            'accepts_data_processing' => true,
            ...$overrides,
        ]);
    }

    // -----------------------------------------------------------------
    // One step instead of two
    // -----------------------------------------------------------------

    public function test_a_registration_goes_straight_into_the_review_queue(): void
    {
        $this->register()
            ->assertCreated()
            // The screen reads this to decide whether a second step follows; it must
            // never be left to guess.
            ->assertJsonPath('verification_required', false);

        $merchant = Merchant::firstOrFail();

        $this->assertSame(MerchantStatus::Pending, $merchant->status);
        // Submitted the moment it was filled in, which is what puts it in front of
        // the supervisor.
        $this->assertNotNull($merchant->submitted_at);
    }

    public function test_no_code_is_sent_and_no_code_is_needed(): void
    {
        $this->register()->assertCreated();

        Mail::assertNotSent(VerificationCodeMail::class);
    }

    public function test_the_supervisor_is_notified_once_at_submission(): void
    {
        User::factory()->platformAdmin()->create();

        $this->register()->assertCreated();

        // With verification on this arrives after the code is confirmed. With it
        // off there is nothing to wait for, so it arrives at submission — and only
        // once.
        Mail::assertSent(NewMerchantSubmissionMail::class, 1);
    }

    public function test_nothing_is_claimed_to_be_verified(): void
    {
        /*
         * The step was skipped, not passed. Writing a verification timestamp would
         * tell the supervisor's review screen that an address was proven when
         * nobody proved anything — and that screen is where the decision is made.
         */
        $this->register()->assertCreated();

        $merchant = Merchant::firstOrFail();

        $this->assertNull($merchant->email_verified_at);
        $this->assertNull($merchant->phone_verified_at);
    }

    // -----------------------------------------------------------------
    // Everything else holds unchanged
    // -----------------------------------------------------------------

    public function test_the_account_still_grants_nothing_until_activation(): void
    {
        $this->register()->assertCreated();

        // Refused as a validation error on the email field, the same way the login
        // screen reports every other refusal — a pending account is not a
        // different kind of "no" to the person typing.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@alwaha.test',
            'password' => 'waha-2026',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_owner_still_keeps_the_password_they_chose(): void
    {
        $this->register()->assertCreated();

        $owner = User::withoutGlobalScopes()->where('email', 'owner@alwaha.test')->firstOrFail();

        $this->assertTrue(password_verify('waha-2026', $owner->password));
    }

    public function test_the_commercial_register_is_still_taken_by_one_submission(): void
    {
        $this->register()->assertCreated();

        // Skipping verification must not turn the register into a free-for-all:
        // FR-MER-03 still holds from the moment the request is submitted.
        $this->register(['email' => 'someone@else.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commercial_register');
    }

    public function test_the_email_is_still_taken_by_one_submission(): void
    {
        $this->register()->assertCreated();

        $this->register(['commercial_register' => 'CR-99887766'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_the_supervisor_can_activate_a_submission_that_was_never_verified(): void
    {
        /*
         * The guard that blocks activation before verification is what makes this
         * setting usable at all: with verification off there is no code to confirm,
         * so an unverified submission has to be approvable or nothing could ever be
         * approved.
         */
        $admin = User::factory()->platformAdmin()->create();

        $this->register()->assertCreated();

        $merchant = Merchant::firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/activate")
            ->assertOk();

        $this->assertSame(MerchantStatus::Active, $merchant->refresh()->status);

        // ...and now the owner can sign in with the password they chose.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@alwaha.test',
            'password' => 'waha-2026',
        ])->assertOk();
    }
}
