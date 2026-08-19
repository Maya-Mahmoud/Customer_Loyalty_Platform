<?php

namespace App\Mail;

use App\Enums\MerchantStatus;
use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a merchant the outcome of a review or a status change (BRD 8.1 step 5,
 * FR-ADM-02). One mailable covers rejection, suspension and reactivation because
 * the three differ only in the status and the reason.
 */
class MerchantDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Merchant $merchant,
        public readonly MerchantStatus $status,
        public readonly ?string $reason = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->status) {
            MerchantStatus::Rejected => __('Your registration request was not approved'),
            MerchantStatus::Suspended => __('Your store account has been suspended'),
            MerchantStatus::Active => __('Your store account is active'),
            MerchantStatus::Pending => __('Your registration request was received'),
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.merchant-decision');
    }
}
