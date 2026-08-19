<?php

namespace App\Enums;

/**
 * Codes are scoped by purpose so a code issued for one flow can never be
 * replayed in another.
 */
enum VerificationPurpose: string
{
    /** Proving the owner's email during registration (BRD FR-MER-02). */
    case MerchantRegistration = 'merchant_registration';

    /** Recovering a forgotten password. */
    case PasswordReset = 'password_reset';

    /** The optional customer check a merchant may switch on (BRD AF-07). */
    case CustomerRegistration = 'customer_registration';

    /** The self-service balance lookup of BRD FR-CUS-12, phase two. */
    case CustomerSelfService = 'customer_self_service';
}
