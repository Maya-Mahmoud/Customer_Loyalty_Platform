<?php

namespace App\Console\Commands;

use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\SubscriptionExpiringMail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * BRD FR-ADM-05: warn the owner and the supervisor before a subscription lapses.
 *
 * Reminders go out at fixed distances from the end date rather than every day in
 * the window. That keeps it stateless — no "last notified" column to keep in sync
 * — while still nagging harder as the date approaches.
 */
class NotifyExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:notify-expiring';

    protected $description = 'Email merchants and platform supervisors about subscriptions nearing expiry';

    public function handle(): int
    {
        $thresholds = $this->thresholds();
        $supervisors = $this->supervisorEmails();
        $sent = 0;

        $merchants = Merchant::query()
            ->where('status', MerchantStatus::Active)
            ->whereNotNull('subscription_ends_at')
            ->whereBetween('subscription_ends_at', [
                now()->startOfDay(),
                now()->addDays(max($thresholds))->endOfDay(),
            ])
            ->get();

        foreach ($merchants as $merchant) {
            $daysRemaining = (int) now()->startOfDay()->diffInDays($merchant->subscription_ends_at, absolute: true);

            if (! in_array($daysRemaining, $thresholds, strict: true)) {
                continue;
            }

            $mail = new SubscriptionExpiringMail($merchant, $daysRemaining);

            Mail::to($merchant->email)->send($mail);

            foreach ($supervisors as $email) {
                Mail::to($email)->send(new SubscriptionExpiringMail($merchant, $daysRemaining));
            }

            $sent++;
            $this->line("Notified {$merchant->name} ({$daysRemaining} days remaining)");
        }

        $this->info("Sent {$sent} expiry notice(s).");

        return self::SUCCESS;
    }

    /**
     * The configured warning distance, plus tighter reminders inside it.
     *
     * @return list<int>
     */
    private function thresholds(): array
    {
        $configured = (int) config('clp.subscription_expiry_warning_days');

        $thresholds = array_values(array_unique(array_filter(
            [$configured, 7, 3, 1],
            fn (int $days) => $days > 0 && $days <= $configured,
        )));

        return $thresholds === [] ? [1] : $thresholds;
    }

    /**
     * @return list<string>
     */
    private function supervisorEmails(): array
    {
        return User::withoutGlobalScopes()
            ->where('role', UserRole::PlatformAdmin)
            ->where('status', UserStatus::Active)
            ->pluck('email')
            ->all();
    }
}
