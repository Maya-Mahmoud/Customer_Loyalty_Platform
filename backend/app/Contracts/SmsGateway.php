<?php

namespace App\Contracts;

/**
 * The seam for the SMS or WhatsApp provider listed as a dependency in BRD 5.5.
 *
 * No provider is contracted yet and the channel itself is still an open decision
 * (BRD OD-08), so the application only ever talks to this interface. Swapping in
 * a real gateway is one new class and one config line.
 */
interface SmsGateway
{
    /**
     * Returns true when the provider accepted the message for delivery. Delivery
     * itself is asynchronous and reported separately.
     */
    public function send(string $phone, string $message): bool;
}
