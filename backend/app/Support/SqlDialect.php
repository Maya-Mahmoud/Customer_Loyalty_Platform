<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The handful of date expressions that differ between the database that runs the
 * tests and the one that runs the shop.
 *
 * MySQL is production and SQLite is the test suite, and HOUR() and DATEDIFF() exist
 * only in the first. Writing them raw would leave everything that depends on them
 * untestable — and a report or a fraud control nobody can verify is a comforting
 * label rather than a control. Kept in one place so the next such difference has an
 * obvious home instead of being solved twice.
 */
final class SqlDialect
{
    public static function hour(string $column): string
    {
        return self::isSqlite()
            ? "CAST(strftime('%H', {$column}) AS INTEGER)"
            : "HOUR({$column})";
    }

    public static function daysBetween(string $earlier, string $later): string
    {
        return self::isSqlite()
            ? "CAST(julianday(date({$later})) - julianday(date({$earlier})) AS INTEGER)"
            : "DATEDIFF({$later}, {$earlier})";
    }

    private static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
