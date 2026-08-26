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

/*
 * BRD BR-017: write off accumulations left untouched past the merchant's validity
 * window. Runs before the shops open, so a customer is never told their balance
 * lapsed halfway through a purchase.
 */
Schedule::command('balances:expire')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping();
