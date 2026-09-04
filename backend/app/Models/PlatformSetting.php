<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Settings the platform supervisor edits from a screen.
 *
 * The table is key/value, but nothing outside this class should know that: callers
 * ask for `PlatformSetting::billingCurrency()` and get a currency, so a caller
 * cannot misspell a key and silently read a default forever. Every setting that
 * gets added here should arrive as a named method for the same reason.
 *
 * Reads fall back to config/clp.php rather than to a literal. The config value is
 * what a fresh installation runs on before the supervisor has ever opened the
 * screen, so the two must not be able to disagree.
 */
class PlatformSetting extends Model
{
    /** The key is the identity: there is no surrogate id to autoincrement. */
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public const BILLING_CURRENCY = 'billing_currency';

    /**
     * The money the platform charges its shops in (BRD 5, subscription plans).
     *
     * One currency for the whole platform, not one per shop: the plan prices are a
     * single price list, and a per-shop billing currency would mean two shops paying
     * different money for the same plan with no exchange rate anywhere to reconcile
     * them. A shop's own trading currency is a separate setting on the shop itself.
     */
    public static function billingCurrency(): string
    {
        return static::get(self::BILLING_CURRENCY) ?? (string) config('clp.default_currency');
    }

    public static function get(string $key): ?string
    {
        return static::query()->find($key)?->value;
    }

    public static function set(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
