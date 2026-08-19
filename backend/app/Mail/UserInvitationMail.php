<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a user to set their own password (BRD FR-BRN-04).
 *
 * Used both for the owner of a freshly activated merchant and for staff created
 * later, so no password is ever sent by email.
 */
class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $invitationUrl,
        public readonly int $expiresInHours,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Set your password for :app', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.user-invitation');
    }
}
