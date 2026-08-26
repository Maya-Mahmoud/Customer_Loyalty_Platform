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

    /*
     * Thresholds for the anti-fraud signals of BRD 12.
     *
     * They live here rather than in the detectors because they are policy, not
     * logic: a busy supermarket and a quiet boutique have different ideas of
     * "unusual", and the owner tuning them should not need a code change. They are
     * deliberately forgiving — a signal that fires on ordinary trading teaches the
     * reader to ignore the screen, which is worse than having no screen.
     */
    'fraud' => [
        /** AF-10: entries recorded outside these hours are worth a second look. */
        'business_hours' => [
            'from' => (int) env('FRAUD_BUSINESS_HOUR_FROM', 7),
            'to' => (int) env('FRAUD_BUSINESS_HOUR_TO', 23),
        ],

        /** How many out-of-hours entries by one person before it is a pattern. */
        'out_of_hours_min' => 3,

        /** AF-02: correction requests by one person, and their share of entries. */
        'corrections_min' => 3,
        'corrections_share' => 0.15,

        /**
         * An invoice keyed in this many days after it happened. Back-dating is how
         * a sale is fitted into a cycle that has already been measured.
         */
        'backdated_days' => 7,
        'backdated_min' => 3,

        /** AF-05: rewards paid to the same customer inside the window. */
        'redemptions_min' => 3,

        /**
         * AF-03 and AF-11: a rep who registered a customer and then entered most of
         * that customer's purchases. One rep and one customer, alone together.
         */
        'concentration_share' => 0.9,
        'concentration_min_invoices' => 4,
    ],
];
