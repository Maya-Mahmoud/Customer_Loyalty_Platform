<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Mail\UserInvitationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-time links that let an invited user set their first password.
 *
 * Used for staff created by a store owner (BRD FR-BRN-04): they have no password,
 * and their mailbox has not been proven, so the link does both jobs at once.
 *
 * Neither a merchant owner registering nor anyone recovering a password needs this.
 * Owners choose a password on the registration form, and a forgotten password is
 * recovered with an emailed code through PasswordResetService — both cases already
 * have a proven mailbox, so a link would prove nothing extra.
 *
 * Only the SHA-256 of the token is stored, so a leaked database yields no working
 * links. Lookup stays a single indexed query because the hash is deterministic.
 */
class InvitationService
{
    /**
     * Invites a user who has never had a password. Marks them Invited, since they
     * cannot sign in until they follow the link.
     */
    public function invite(User $user): string
    {
        $token = $this->issueToken($user);

        $user->forceFill(['status' => UserStatus::Invited])->save();

        Mail::to($user->email)->send(new UserInvitationMail(
            user: $user,
            invitationUrl: $this->urlFor($token),
            expiresInHours: $this->ttlHours(),
        ));

        return $token;
    }

    /**
     * Resolves a token to its user, ignoring the tenant scope: the holder is not
     * signed in, so no merchant is in context.
     */
    public function resolve(string $token): ?User
    {
        return User::withoutGlobalScopes()
            ->where('invitation_token', $this->hash($token))
            ->where('invitation_expires_at', '>', now())
            ->first();
    }

    /**
     * Consumes the link and activates the user. The token is cleared so the same
     * link cannot set a second password.
     */
    public function accept(string $token, string $password): User
    {
        $user = $this->resolve($token);

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => __('This link is invalid or has expired.'),
            ]);
        }

        $user->forceFill([
            'password' => $password,
            'status' => UserStatus::Active,
            'invitation_token' => null,
            'invitation_expires_at' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    /**
     * Issues a fresh token, which retires any link sent earlier.
     */
    private function issueToken(User $user): string
    {
        $token = Str::random(48);

        $user->forceFill([
            'invitation_token' => $this->hash($token),
            'invitation_sent_at' => now(),
            'invitation_expires_at' => now()->addHours($this->ttlHours()),
        ])->save();

        return $token;
    }

    private function urlFor(string $token): string
    {
        return config('clp.frontend_url').'/set-password/'.$token;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function ttlHours(): int
    {
        return (int) config('clp.invitation_ttl_hours');
    }
}
