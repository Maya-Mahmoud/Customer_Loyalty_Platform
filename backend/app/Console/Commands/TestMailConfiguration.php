<?php

namespace App\Console\Commands;

use App\Mail\VerificationCodeMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves the mail configuration actually delivers, before anyone relies on it.
 *
 * Registration and activation both hang off email — the verification code and the
 * invitation link that sets the owner's password. Discovering during a demo that
 * SMTP was misconfigured is the failure this command exists to prevent.
 */
class TestMailConfiguration extends Command
{
    protected $signature = 'mail:test {recipient : Address to send the sample to}';

    protected $description = 'Send a sample verification email to confirm the mail configuration works';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');
        $mailer = config('mail.default');

        $this->line("Mailer:    <fg=cyan>{$mailer}</>");

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER is "log": nothing is sent, messages are written to storage/logs/laravel.log.');
        }

        if ($mailer === 'smtp') {
            $this->line('Host:      <fg=cyan>'.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').'</>');
            $this->line('Username:  <fg=cyan>'.config('mail.mailers.smtp.username').'</>');

            if ($this->looksLikePlaceholder()) {
                $this->error('MAIL_USERNAME or MAIL_PASSWORD is still a placeholder. Fill them in .env first.');

                return self::FAILURE;
            }
        }

        $this->line("From:      <fg=cyan>".config('mail.from.address')."</>");
        $this->line("To:        <fg=cyan>{$recipient}</>");
        $this->newLine();

        try {
            Mail::to($recipient)->send(new VerificationCodeMail('123456', 10));
        } catch (Throwable $e) {
            $this->error('Delivery failed: '.$e->getMessage());
            $this->newLine();
            $this->line('Common causes with Gmail:');
            $this->line('  - using the account password instead of a 16-character App Password');
            $this->line('  - two-step verification not enabled on the Google account');
            $this->line('  - MAIL_FROM_ADDRESS different from MAIL_USERNAME');

            return self::FAILURE;
        }

        $this->info('Sent. Check the inbox (and the spam folder) for a sample code of 123456.');

        return self::SUCCESS;
    }

    private function looksLikePlaceholder(): bool
    {
        $values = [
            (string) config('mail.mailers.smtp.username'),
            (string) config('mail.mailers.smtp.password'),
        ];

        foreach ($values as $value) {
            if ($value === '' || str_contains($value, 'PUT_YOUR')) {
                return true;
            }
        }

        return false;
    }
}
