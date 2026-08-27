<?php

namespace App\Services;

use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationChannel;
use App\Enums\VerificationPurpose;
use App\Mail\NewMerchantSubmissionMail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Self-registration of a merchant, BRD 8.1 steps 1 to 4.
 *
 * The record is created straight away but in Pending status with nothing verified,
 * so it grants no access at all. FR-MER-02 asks for the email and phone to be
 * proven "before the account is created"; holding a half-filled form in a session
 * would be worse for a stateless API, so the record exists and stays inert until
 * the code is confirmed. Only then does it enter the supervisor's queue.
 *
 * Only the email address is verified. BRD FR-MER-02 asks for the phone number too,
 * and that was dropped deliberately:
 *
 *  - the email is on the critical path — it is the sign-in identifier, it carries
 *    the invitation link that sets the owner's password, and it receives the
 *    review decision. An unverified typo there leaves an activated account nobody
 *    can reach;
 *  - the owner's phone number is contact information only in release one. Customer
 *    notifications go to customer numbers, not to this one;
 *  - SMS is a metered cost that BRD 5.4 and RSK-05 both call out, while email is
 *    effectively free. Verifying the free channel and skipping the paid one is the
 *    cheaper half of the requirement to keep.
 *
 * phone_verified_at stays null and the supervisor sees that on the review screen.
 * The SMS gateway and the Sms channel remain in place for the customer
 * notifications of FR-NOT-01 onwards.
 */
class MerchantRegistrationService
{
    private const PURPOSE = VerificationPurpose::MerchantRegistration;

    private const CHANNEL = VerificationChannel::Email;

    public function __construct(
        private readonly VerificationCodeService $codes,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, ?string $ipAddress = null): Merchant
    {
        $merchant = DB::transaction(function () use ($data, $ipAddress): Merchant {
            $merchant = $this->targetFor($data);

            $merchant->fill([
                'name' => $data['name'],
                'trade_name' => $data['trade_name'] ?? null,
                'commercial_register' => $data['commercial_register'],
                'owner_name' => $data['owner_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'city' => $data['city'],
                // FR-MER-05, amended: the Syrian pound, because that is what the
                // shops this platform sells to price in (see config/clp.php).
                'currency' => $data['currency'] ?? config('clp.default_currency'),
            ]);

            // A re-application starts over: previous verification and the
            // previous rejection reason both stop counting.
            $merchant->forceFill([
                'status' => MerchantStatus::Pending,
                'status_reason' => null,
                'status_changed_at' => now(),
                'email_verified_at' => null,
                'phone_verified_at' => null,
                'submitted_at' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            $this->syncOwner($merchant, $data);

            /*
             * Inside the transaction on purpose. A registration whose code never
             * went out is not merely incomplete — it takes the commercial
             * register, so the applicant cannot even try again. Rolling the whole
             * thing back leaves them free to retry once mail is working.
             */
            $this->issueCode($merchant, $ipAddress);

            return $merchant;
        });

        $this->audit->record(
            action: 'merchant.registration_submitted',
            entity: $merchant,
            after: $merchant->only(['name', 'commercial_register', 'email', 'city']),
        );

        return $merchant;
    }

    /**
     * Confirms the emailed code and puts the request in front of the supervisor
     * (BRD 8.1 step 4).
     */
    public function verify(string $email, string $code): Merchant
    {
        $merchant = $this->pendingByEmail($email);

        $this->codes->confirm(self::PURPOSE, self::CHANNEL, $merchant->email, $code, 'code');

        $merchant->forceFill([
            'email_verified_at' => now(),
            'submitted_at' => now(),
        ])->save();

        $this->notifySupervisors($merchant);

        $this->audit->record(
            action: 'merchant.registration_verified',
            entity: $merchant,
            after: ['submitted_at' => $merchant->submitted_at?->toIso8601String()],
        );

        return $merchant;
    }

    /**
     * Sends a new code. Rate limiting lives in the code service, so a resend loop
     * cannot be used to hammer the mailbox.
     */
    public function resend(string $email, ?string $ipAddress = null): Merchant
    {
        $merchant = $this->pendingByEmail($email);

        $this->issueCode($merchant, $ipAddress);

        return $merchant;
    }

    /**
     * Either the rejected record being re-submitted, or a brand new one.
     *
     * BRD FR-MER-03 refuses a commercial register that is already in use, while
     * BRD 8.1 explicitly allows a rejected applicant to correct and re-apply — so
     * only a rejected row is reusable.
     *
     * @param  array<string, mixed>  $data
     */
    private function targetFor(array $data): Merchant
    {
        $existing = Merchant::where('commercial_register', $data['commercial_register'])->first();

        if ($existing !== null && $existing->status !== MerchantStatus::Rejected) {
            throw ValidationException::withMessages([
                'commercial_register' => __('This commercial register is already registered. Use account recovery instead of registering again.'),
            ]);
        }

        $this->guardEmailIsFree($data['email'], $existing?->getKey());

        return $existing ?? new Merchant();
    }

    /**
     * The email identifies the owner, so it has to be free across merchants and
     * across staff accounts alike.
     */
    private function guardEmailIsFree(string $email, ?int $ignoreMerchantId): void
    {
        $merchantConflict = Merchant::where('email', $email)
            ->when($ignoreMerchantId !== null, fn ($query) => $query->whereKeyNot($ignoreMerchantId))
            ->exists();

        $userConflict = User::withoutGlobalScopes()
            ->where('email', $email)
            ->when(
                $ignoreMerchantId !== null,
                fn ($query) => $query->where(fn ($inner) => $inner
                    ->whereNull('merchant_id')
                    ->orWhere('merchant_id', '!=', $ignoreMerchantId))
            )
            ->exists();

        if ($merchantConflict || $userConflict) {
            throw ValidationException::withMessages([
                'email' => __('This email address is already in use.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncOwner(Merchant $merchant, array $data): User
    {
        $owner = User::withoutGlobalScopes()
            ->where('merchant_id', $merchant->getKey())
            ->where('role', UserRole::MerchantOwner)
            ->first() ?? new User();

        $owner->forceFill([
            'merchant_id' => $merchant->getKey(),
            'branch_id' => null,
            'name' => $data['owner_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'role' => UserRole::MerchantOwner,

            /*
             * Active from the outset, with the password chosen on the form. This
             * grants nothing: canSignIn() also requires the merchant itself to be
             * active, so the account stays shut until a supervisor approves it.
             * Marking the user Invited instead would be misleading — there is no
             * invitation to accept.
             */
            'status' => UserStatus::Active,
            'password' => $data['password'],
            'invitation_token' => null,
            'invitation_expires_at' => null,
        ])->save();

        return $owner;
    }

    private function issueCode(Merchant $merchant, ?string $ipAddress): void
    {
        $this->codes->issue(
            purpose: self::PURPOSE,
            channel: self::CHANNEL,
            destination: $merchant->email,
            verifiable: $merchant,
            field: 'code',
            ipAddress: $ipAddress,
        );
    }

    private function pendingByEmail(string $email): Merchant
    {
        $merchant = Merchant::where('email', $email)
            ->where('status', MerchantStatus::Pending)
            ->first();

        if ($merchant === null) {
            throw ValidationException::withMessages([
                'email' => __('No pending registration was found for this email address.'),
            ]);
        }

        return $merchant;
    }

    /**
     * Internal heads-up for the review queue.
     *
     * Failures are swallowed on purpose. The applicant has done everything asked of
     * them and their request is already queued; letting a bounced or throttled
     * internal notice turn that into an error would block a registration for a
     * reason that has nothing to do with the applicant. The queue on the console is
     * the source of truth either way, and the failure still reaches the log.
     */
    private function notifySupervisors(Merchant $merchant): void
    {
        $supervisors = User::withoutGlobalScopes()
            ->where('role', UserRole::PlatformAdmin)
            ->where('status', UserStatus::Active)
            ->pluck('email');

        foreach ($supervisors as $email) {
            try {
                Mail::to($email)->send(new NewMerchantSubmissionMail($merchant));
            } catch (Throwable $e) {
                Log::warning('Could not notify a platform supervisor of a new registration.', [
                    'merchant_id' => $merchant->getKey(),
                    'supervisor' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
