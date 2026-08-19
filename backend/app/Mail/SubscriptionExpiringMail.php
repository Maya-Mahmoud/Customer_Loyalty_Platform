<?php

namespace App\Mail;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Advance warning before a subscription lapses (BRD FR-ADM-05). Sent to the
 * merchant owner and to the platform supervisors.
 */
class SubscriptionExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Merchant $merchant,
        public readonly int $daysRemaining,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Subscription expires in :days days', ['days' => $this->daysRemaining]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.subscription-expiring');
    }
}
