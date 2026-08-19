<?php

namespace App\Support;

/**
 * Normalises phone numbers to a single stored form.
 *
 * The mobile number is the customer's unique identifier inside a merchant
 * (BRD BR-002), so "0991 234 567" and "0991234567" must not become two records.
 * Everything that stores a phone runs it through here first.
 */
class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // Keep a leading + for international numbers, drop every other separator.
        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        return $hasPlus ? '+'.$digits : $digits;
    }

    /**
     * Hides the middle of a number so a screen can confirm which one a code went
     * to without displaying it in full.
     */
    public static function mask(?string $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null || strlen($normalized) < 5) {
            return $normalized;
        }

        return substr($normalized, 0, 3).str_repeat('*', strlen($normalized) - 5).substr($normalized, -2);
    }
}
