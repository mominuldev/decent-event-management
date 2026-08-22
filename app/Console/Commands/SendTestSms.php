<?php

namespace App\Console\Commands;

use App\Domain\Notification\Gateways\ReveSmsClient;
use App\Domain\Notification\Support\Msisdn;
use App\Domain\Notification\Support\SmsGatewayConfig;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends one real SMS through {@see ReveSmsClient} — the first live call
 * this integration needs, and the one that turns the inferred response
 * shape documented on that class into a known one.
 *
 * It prints the raw gateway response deliberately. Every response field
 * name in the client is an inference from the vendor's request-only
 * material, so what this prints is the thing to paste back into
 * `ReveSmsClient`'s parser to tighten it. `SslCommerzClient` shipped in
 * exactly this position and its first live call found two real defects.
 *
 * It writes no outbox row and ignores the kill switches: this checks the
 * gateway, not the notification pipeline on top of it. Use `--status` to
 * ask about a message id afterwards, and `--balance` for the prepaid
 * balance.
 */
class SendTestSms extends Command
{
    protected $signature = 'sms:test
        {recipient? : Mobile number to send to (any format — it is normalised to an MSISDN)}
        {--message= : Message body; defaults to a short Latin probe}
        {--bangla : Send a Bangla probe instead, to prove Unicode segments end to end}
        {--status= : Skip sending and report the delivery status of this message id}
        {--balance : Skip sending and report the account balance}';

    protected $description = 'Send a test SMS through the configured gateway, or query a status or balance';

    public function handle(ReveSmsClient $client): int
    {
        $missing = ReveSmsClient::missingCredentials();

        if ($missing !== []) {
            $this->components->error('SMS is not configured — missing: '.implode(', ', $missing).'.');
            $this->components->warn('Set these in the admin console under Settings → SMS gateway, or in .env as REVESMS_*. All three are required.');

            return self::FAILURE;
        }

        // Through the resolver, not config() — a credential set on the
        // Settings screen overrides the environment, so reading config here
        // would report an endpoint and sender the send is not actually
        // using. (It did exactly that until this was fixed: the header said
        // smpp.revesms.com while the message went to the configured host.)
        $settings = app(SmsGatewayConfig::class);

        $this->components->twoColumnDetail('Endpoint', (string) $settings->get('base_url'));
        $this->components->twoColumnDetail(
            'Sender ID',
            (string) $settings->get('sender_id').($settings->maskingEnabled() ? ' (masking)' : ' (non-masking)'),
        );
        $this->components->twoColumnDetail('Transport', strtoupper((string) config('services.revesms.method')).' / '.config('services.revesms.auth_style').' auth');

        try {
            if ($this->option('balance')) {
                return $this->reportBalance($client);
            }

            if (is_string($this->option('status')) && $this->option('status') !== '') {
                return $this->reportStatus($client, (string) $this->option('status'));
            }

            return $this->sendProbe($client);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function sendProbe(ReveSmsClient $client): int
    {
        $recipient = $this->argument('recipient');

        if (! is_string($recipient) || $recipient === '') {
            $this->components->error('A recipient is required unless --status or --balance is given.');

            return self::FAILURE;
        }

        $msisdn = Msisdn::format($recipient);

        if ($msisdn === null) {
            $this->components->error("Not a dialable number: {$recipient}");

            return self::FAILURE;
        }

        $message = is_string($this->option('message')) && $this->option('message') !== ''
            ? (string) $this->option('message')
            : ($this->option('bangla')
                ? 'শতবর্ষ উদযাপন — এটি একটি পরীক্ষামূলক বার্তা।'
                : 'Decent Event: test message. No action needed.');

        $segments = SmsSegmentCalculator::segmentCount($message);

        $this->components->twoColumnDetail('To', $msisdn.($msisdn === $recipient ? '' : " (from {$recipient})"));
        $this->components->twoColumnDetail('Encoding', SmsSegmentCalculator::isGsm7($message) ? 'GSM-7 (160/segment)' : 'Unicode (70/segment)');
        $this->components->twoColumnDetail('Segments', (string) $segments.' — billed as '.$segments.'x');

        $results = $client->sendText(ReveSmsClient::defaultSenderId(), [$msisdn], $message);

        foreach ($results as $result) {
            $this->newLine();
            $this->components->twoColumnDetail('Accepted', $result->accepted ? '<fg=green>yes</>' : '<fg=red>no</>');
            $this->components->twoColumnDetail('Message ID', $result->messageId ?? '<fg=red>none returned</>');
            $this->components->twoColumnDetail('Status code', $result->statusCode ?? '—');
            $this->components->twoColumnDetail('Status text', $result->statusText ?? '—');
            $this->newLine();
            $this->line('  Raw response (paste this into ReveSmsClient if the parser needs tightening):');
            $this->line('  '.json_encode($result->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $accepted = $results[0]->accepted && $results[0]->messageId !== null;

        if ($accepted) {
            $this->newLine();
            $this->components->info("Check delivery with: php artisan sms:test --status={$results[0]->messageId}");
        }

        return $accepted ? self::SUCCESS : self::FAILURE;
    }

    private function reportStatus(ReveSmsClient $client, string $messageId): int
    {
        $status = $client->status($messageId);

        $this->components->twoColumnDetail('Message ID', $status->messageId);
        $this->components->twoColumnDetail('Gateway status', $status->providerStatus ?? '—');
        $this->components->twoColumnDetail('Mapped to', $status->state);
        $this->line('  '.json_encode($status->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function reportBalance(ReveSmsClient $client): int
    {
        $balance = $client->balance();

        $this->components->twoColumnDetail(
            'Balance',
            $balance['balance'] !== null ? number_format($balance['balance'], 2) : '<fg=yellow>not found in response</>',
        );
        $this->line('  '.json_encode($balance['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
