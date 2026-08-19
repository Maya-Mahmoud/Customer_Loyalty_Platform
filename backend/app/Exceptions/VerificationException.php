<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Verification failures are user input problems, so they surface as 422 with the
 * message on the offending field — the shape the Angular forms already render.
 */
class VerificationException
{
    public static function invalidCode(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => __('This code is incorrect or has expired. Request a new one.'),
        ]);
    }

    public static function tooSoon(string $field, int $secondsRemaining): ValidationException
    {
        return ValidationException::withMessages([
            $field => __('Please wait :seconds seconds before requesting another code.', [
                'seconds' => $secondsRemaining,
            ]),
        ]);
    }

    public static function hourlyLimitReached(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => __('Too many codes requested. Please try again later.'),
        ]);
    }
}
