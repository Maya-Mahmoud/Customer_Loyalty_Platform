<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Syrian pound as the column default (BRD FR-MER-05, amended).
 *
 * The document names USD. The platform sells to Syrian shops that price in Syrian
 * pounds, so a USD default is a setting every owner has to find and change before
 * their first sale — and the one who does not find it prices their whole loyalty
 * programme in a currency they never use.
 *
 * Only the default moves. Stores already trading keep whatever they were set to,
 * because their recorded amounts were entered in it and a currency is a label on
 * money that has already changed hands, not a conversion.
 *
 * Skipped on SQLite, which cannot alter a column default in place and would need the
 * table rebuilt for it. Nothing is lost by skipping: the application always supplies
 * a currency when it creates a merchant (see config/clp.php), so the column default
 * is a statement about the schema rather than a value anybody relies on — and SQLite
 * is where the tests run, not where the shops do.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsAlteringDefaults()) {
            return;
        }

        DB::statement("ALTER TABLE merchants ALTER COLUMN currency SET DEFAULT 'SYP'");
    }

    public function down(): void
    {
        if (! $this->supportsAlteringDefaults()) {
            return;
        }

        DB::statement("ALTER TABLE merchants ALTER COLUMN currency SET DEFAULT 'USD'");
    }

    private function supportsAlteringDefaults(): bool
    {
        return Schema::getConnection()->getDriverName() !== 'sqlite';
    }
};
