<?php

return [
    /*
     * One-time code policy. Short-lived and attempt-limited, which is the control
     * BRD AF-12 asks for on any code-protected screen.
     */
    'code_length' => 6,

    'ttl_minutes' => env('VERIFICATION_TTL_MINUTES', 10),

    /** Wrong entries allowed before the code is burned. */
    'max_attempts' => env('VERIFICATION_MAX_ATTEMPTS', 5),

    /** Minimum wait before the same destination may request another code. */
    'resend_cooldown_seconds' => env('VERIFICATION_RESEND_COOLDOWN', 60),

    /** Ceiling per destination per hour, so the SMS bill cannot be run up. */
    'max_per_hour' => env('VERIFICATION_MAX_PER_HOUR', 6),
];
