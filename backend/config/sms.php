<?php

return [
    /*
     * Which gateway implementation to bind. 'log' writes messages to the log so
     * the flow can be exercised before a provider is contracted (BRD 5.5, OD-08).
     */
    'driver' => env('SMS_DRIVER', 'log'),

    'log_channel' => env('SMS_LOG_CHANNEL', 'stack'),

    'from' => env('SMS_FROM', 'Loyalty'),
];
