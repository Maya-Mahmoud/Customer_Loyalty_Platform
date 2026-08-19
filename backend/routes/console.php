<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work. Requires the single cron entry that drives Laravel's scheduler:
 *   * * * * * php artisan schedule:run
 */

// BRD FR-ADM-05: advance warning before a subscription lapses.
Schedule::command('subscriptions:notify-expiring')
    ->dailyAt('08:00')
    ->onOneServer();
