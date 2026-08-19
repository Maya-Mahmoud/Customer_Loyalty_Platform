<?php

namespace App\Services;

use App\Enums\VerificationChannel;
use App\Enums\VerificationPurpose;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Recovery for a forgotten password, by emailed code.
 *
 * A code rather than a link, for the same reasons registration uses one: the whole
 * recovery fits on a single screen, and it still works when the mailbox is on a
 * phone while the browser is on a laptop.
 *
 * A generated password is never sent. One mailed in clear text stays readable in
 * the mailbox indefinitely, so anyone who reaches that inbox later reaches the
 * account.
 *
 * The invitation link in InvitationService remains for staff accounts created by
 * an owner (BRD FR-BRN-04) — those users have no password to recover and no
 * mailbox proven yet.
 */
class PasswordResetService
{
    private const PURPOSE = VerificationPurpose::PasswordReset;

    private const CHANNEL = VerificationChannel::Email;

    public function __construct(
        private readonly VerificationCodeService $codes,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Emails a code, but only to an account that could actually use one.
     *
     * Silent when the address is unknown or the account is shut: the caller answers
     * identically either way, so this cannot be used to discover who is registered.
     */
    public function request(string $email, ?string $ipAddress = null): void
    {
        $user = $this->findUsable($email);

        if ($user === null) {
            return;
        }

        $code = $this->codes->issue(
            purpose: self::PURPOSE,
            channel: self::CHANNEL,
            destination: $user->email,
            verifiable: $user,
            field: 'code',
            ipAddress: $ipAddress,
        );

        Mail::to($user->email)->send(new PasswordResetMail(
            user: $user,
            code: $code,
            expiresInMinutes: (int) config('verification.ttl_minutes'),
        ));

        $this->audit->record('auth.password_reset_requested', entity: $user, actor: $user);
    }

    /**
     * Checks the code and sets the new password.
     *
     * The code is validated but not spent until the password itself passes, so a
     * password the policy rejects does not burn the code and force a fresh email.
     */
    public function reset(string $email, string $code, string $password): User
    {
        $user = $this->findUsable($email);

        if ($user === null) {
            // Same answer as a wrong code, so a bad address reveals nothing.
            throw ValidationException::withMessages([
                'code' => __('This code is incorrect or has expired. Request a new one.'),
            ]);
        }

        $record = $this->codes->check(self::PURPOSE, self::CHANNEL, $user->email, $code, 'code');

        DB::transaction(function () use ($user, $password, $record): void {
            $user->forceFill([
                'password' => $password,
                'email_verified_at' => $user->email_verified_at ?? now(),
                // Any invitation link outstanding for this user is now moot.
                'invitation_token' => null,
                'invitation_expires_at' => null,
            ])->save();

            $this->codes->consume($record);

            // Whoever knew the old password loses their sessions. If the reset was
            // prompted by a suspected compromise, leaving them alive would defeat it.
            $user->tokens()->delete();
        });

        $this->audit->record('auth.password_reset_completed', entity: $user, actor: $user);

        return $user;
    }

    /**
     * Ignores the tenant scope: nobody is signed in during a recovery, so no
     * merchant is in context.
     */
    private function findUsable(string $email): ?User
    {
        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        return $user !== null && $user->canSignIn() ? $user : null;
    }
}
