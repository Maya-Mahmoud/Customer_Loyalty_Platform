<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Carries a one-time code for recovering a forgotten password.
 *
 * A code rather than a link, matching how registration already verifies an
 * address: the whole recovery then happens on one screen, and it works when the
 * mailbox is on a phone while the browser is on a laptop.
 *
 * Never a generated password — one mailed in clear text stays readable in the
 * mailbox forever, so whoever reaches that inbox later reaches the account.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $code,
        public readonly int $expiresInMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your password reset code'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset');
    }
}
