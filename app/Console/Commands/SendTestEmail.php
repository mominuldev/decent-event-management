<?php

namespace App\Console\Commands;

use App\Domain\Notification\Channels\MailDriver;
use App\Domain\Notification\Mail\NotificationMail;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves the configured SMTP transport actually delivers, without having
 * to take a registration through to a ticket first.
 *
 * It deliberately sends through {@see NotificationMail} — the same
 * mailable {@see MailDriver} uses — rather than an ad hoc message, so a
 * failure here is a failure of the real path: credentials, TLS scheme,
 * and the From address the provider will or will not accept. A mailbox
 * host typically rejects a From header the authenticated account does
 * not own, so this reports the resolved sender before sending; that
 * mismatch is the most common reason a working password still bounces.
 *
 * It does not write an outbox row and does not touch the kill switches —
 * this checks the transport, not the notification pipeline on top of it.
 *
 * `--ticket=DEC100-...` sends the real confirmation email for an existing
 * ticket instead of the plain probe — same shell, same inline QR — so the
 * message that reaches a gate can be checked in a real inbox without
 * pushing another registration through payment.
 */
class SendTestEmail extends Command
{
    protected $signature = 'mail:test
        {recipient : Address to send the test message to}
        {--ticket= : Ticket number to send the real confirmation email for, QR and all}';

    protected $description = 'Send a test email through the configured mailer';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("Not a valid email address: {$recipient}");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        $this->components->twoColumnDetail('Mailer', $mailer);

        if ($mailer === 'smtp') {
            $this->components->twoColumnDetail('Host', sprintf(
                '%s:%s (%s)',
                (string) config('mail.mailers.smtp.host'),
                (string) config('mail.mailers.smtp.port'),
                config('mail.mailers.smtp.scheme') ?: 'auto',
            ));
            $this->components->twoColumnDetail('Username', (string) config('mail.mailers.smtp.username'));
        }

        $this->components->twoColumnDetail('From', $from);
        $this->components->twoColumnDetail('To', $recipient);

        if ($mailer === 'log') {
            $this->components->warn('MAIL_MAILER=log — this will be written to the log, not delivered.');
        }

        if ($mailer === 'smtp' && $from !== (string) config('mail.mailers.smtp.username')) {
            $this->components->warn('MAIL_FROM_ADDRESS differs from MAIL_USERNAME; most hosts reject that.');
        }

        $locale = (string) config('notifications.locales.email', config('notifications.locales.default', 'en'));

        // The presentation is built outside the Mailable's own `withLocale`,
        // exactly as it is in MailDriver, so the locale has to be in place
        // before `mailPresentation()` is asked for its strings.
        App::setLocale($locale);
        $ticketNumber = $this->option('ticket');
        $ticket = null;

        if ($ticketNumber !== null) {
            $ticket = Ticket::query()->where('ticket_number', $ticketNumber)->first();

            if ($ticket === null) {
                $this->components->error("No ticket numbered {$ticketNumber}.");

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('Ticket', $ticket->ticket_number);
            $this->components->twoColumnDetail('Language', $locale);
        }

        try {
            Mail::to($recipient)->send($ticket !== null
                ? new NotificationMail(
                    'আপনার টিকিট প্রস্তুত — '.$ticket->ticket_number,
                    '<p>প্রিয় '.e((string) ($ticket->holder_name_bn ?: $ticket->holder_name)).',</p>'
                    .'<p>আপনার টিকিট নিশ্চিত হয়েছে। নিচের কোডটিই আপনার প্রবেশপত্র — গেটে ফোন থেকে '
                    .'দেখান, অথবা এই ইমেইলটি প্রিন্ট করে সঙ্গে আনুন।</p>',
                    $ticket->mailPresentation(),
                    $locale,
                )
                : new NotificationMail(
                    config('app.name').' — test email',
                    '<p>This is a test message from <strong>'.e((string) config('app.name')).'</strong>.</p>'
                    .'<p>If you are reading it, the SMTP transport is configured correctly and '
                    .'ticket confirmation emails will be delivered the same way.</p>',
                ));
        } catch (Throwable $e) {
            $this->components->error('Send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Sent. Check the inbox (and the spam folder).');

        return self::SUCCESS;
    }
}
