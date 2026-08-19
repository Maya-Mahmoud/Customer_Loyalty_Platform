<?php

namespace Tests\Feature;

use App\Enums\MerchantStatus;
use App\Mail\MerchantDecisionMail;
use App\Mail\NewMerchantSubmissionMail;
use App\Mail\SubscriptionExpiringMail;
use App\Mail\UserInvitationMail;
use App\Mail\VerificationCodeMail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Emails and the subscription reminder of BRD FR-ADM-05.
 *
 * The rendering cases matter because every other test fakes the mailer, which
 * skips the Blade views entirely — a broken template would otherwise stay
 * invisible until production.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_email_renders(): void
    {
        $merchant = Merchant::factory()->create(['subscription_ends_at' => now()->addDays(14)]);
        $owner = User::factory()->owner($merchant->id)->create();

        $mailables = [
            new VerificationCodeMail('123456', 10),
            new UserInvitationMail($owner, 'https://example.test/set-password/abc', 72),
            new MerchantDecisionMail($merchant, MerchantStatus::Active),
            new MerchantDecisionMail($merchant, MerchantStatus::Rejected, 'Register could not be confirmed'),
            new MerchantDecisionMail($merchant, MerchantStatus::Suspended, 'Unpaid subscription'),
            new NewMerchantSubmissionMail($merchant),
            new SubscriptionExpiringMail($merchant, 14),
        ];

        foreach ($mailables as $mailable) {
            $html = $mailable->render();

            $this->assertNotSame('', trim($html), $mailable::class);
            $this->assertStringContainsString('<html', $html, $mailable::class);
        }
    }

    public function test_arabic_emails_render_right_to_left(): void
    {
        // BRD NFR-07 asks for full Arabic support, which for email means the
        // direction has to follow the locale.
        $this->app->setLocale('ar');

        $html = (new VerificationCodeMail('123456', 10))->render();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);
        // Body copy, not the subject line, so the view itself is proven localised.
        $this->assertStringContainsString('استخدم الرمز التالي', $html);
    }

    public function test_the_verification_email_shows_the_code(): void
    {
        $html = (new VerificationCodeMail('654321', 10))->render();

        $this->assertStringContainsString('654321', $html);
        // Digits must not be flipped by an RTL container.
        $this->assertStringContainsString('direction:ltr', $html);
    }

    public function test_the_invitation_email_never_contains_a_password(): void
    {
        $merchant = Merchant::factory()->create();
        $owner = User::factory()->owner($merchant->id)->create();

        $html = (new UserInvitationMail($owner, 'https://example.test/set-password/abc', 72))->render();

        $this->assertStringContainsString('https://example.test/set-password/abc', $html);
        $this->assertStringNotContainsString($owner->password, $html);
    }

    // -----------------------------------------------------------------
    // Subscription reminders (FR-ADM-05)
    // -----------------------------------------------------------------

    public function test_a_reminder_goes_out_at_the_configured_distance(): void
    {
        Mail::fake();

        $days = (int) config('clp.subscription_expiry_warning_days');

        Merchant::factory()->create([
            'email' => 'owner@expiring.test',
            'subscription_ends_at' => now()->addDays($days)->toDateString(),
        ]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertSent(
            SubscriptionExpiringMail::class,
            fn (SubscriptionExpiringMail $mail) => $mail->hasTo('owner@expiring.test')
                && $mail->daysRemaining === $days
        );
    }

    public function test_the_supervisor_is_copied_on_reminders(): void
    {
        Mail::fake();

        User::factory()->platformAdmin()->create(['email' => 'admin@platform.test']);

        Merchant::factory()->create([
            'subscription_ends_at' => now()->addDays(7)->toDateString(),
        ]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertSent(
            SubscriptionExpiringMail::class,
            fn (SubscriptionExpiringMail $mail) => $mail->hasTo('admin@platform.test')
        );
    }

    public function test_no_reminder_is_sent_between_thresholds(): void
    {
        Mail::fake();

        // Ten days out is inside the window but not on a threshold, so the owner
        // is not nagged every single day.
        Merchant::factory()->create([
            'subscription_ends_at' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertNotSent(SubscriptionExpiringMail::class);
    }

    public function test_inactive_merchants_are_skipped(): void
    {
        Mail::fake();

        Merchant::factory()->suspended()->create([
            'subscription_ends_at' => now()->addDays(7)->toDateString(),
        ]);
        Merchant::factory()->pending()->create([
            'subscription_ends_at' => now()->addDays(7)->toDateString(),
        ]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertNotSent(SubscriptionExpiringMail::class);
    }

    public function test_merchants_without_an_end_date_are_skipped(): void
    {
        Mail::fake();

        Merchant::factory()->create(['subscription_ends_at' => null]);

        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        Mail::assertNotSent(SubscriptionExpiringMail::class);
    }
}
