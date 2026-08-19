<?php

namespace App\Mail;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The notification the platform supervisor receives once a registration has been
 * verified and is waiting for review (BRD 8.1 step 4).
 */
class NewMerchantSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Merchant $merchant)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('New registration request: :merchant', ['merchant' => $this->merchant->name]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-merchant-submission');
    }
}
