<?php

/*
 * Application settings that come straight from the BRD, kept together so a
 * policy change is a one-line edit rather than a search through the code.
 */
return [
    /** Base URL of the Angular app, used to build links inside emails. */
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:4200'), '/'),

    /** How long a password-setting invitation stays valid (BRD FR-BRN-04). */
    'invitation_ttl_hours' => (int) env('INVITATION_TTL_HOURS', 72),

    /** Advance warning before a subscription lapses (BRD FR-ADM-05). */
    'subscription_expiry_warning_days' => (int) env('SUBSCRIPTION_EXPIRY_WARNING_DAYS', 14),

    /**
     * BRD BR-020: after a merchant is suspended, customer data and balances are
     * kept for at least this long before any archiving or deletion.
     */
    'suspended_retention_days' => 90,
];
