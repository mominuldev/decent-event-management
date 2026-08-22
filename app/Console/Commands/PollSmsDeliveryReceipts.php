<?php

namespace App\Console\Commands;

use App\Domain\Notification\Actions\RecordDeliveryReceipt;
use App\Domain\Notification\Gateways\ReveSmsClient;
use App\Domain\Notification\Gateways\ReveSmsDeliveryState;
use App\Domain\Notification\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Asks REVE what happened to every SMS still sitting at `sent`
 * (`/getmultistatus`), and settles it to `delivered` or `bounced`.
 *
 * This exists because the push callback cannot be relied on: pointing
 * REVE at `POST /webhooks/sms/dlr` is a setting on *their* account
 * console, so until somebody with that login makes the change, polling is
 * the only way a delivery state is ever learned. It stays useful
 * afterwards — a callback that is dropped, or that arrives while this app
 * is mid-deploy, leaves a row that only a poll will ever resolve. Both
 * paths write through {@see RecordDeliveryReceipt}, so running both
 * costs a duplicate timeline entry and nothing else.
 *
 * The window matters in both directions. A message is only asked about
 * after `--min-age` (a receipt does not exist the instant a message is
 * accepted, and asking immediately just burns a request per row), and
 * only until `--max-age` — a carrier that has said nothing in two days is
 * not going to, and re-asking forever would grow this sweep without
 * bound across the event.
 */
class PollSmsDeliveryReceipts extends Command
{
    protected $signature = 'sms:poll-dlr
        {--limit=500 : Most notifications to ask about in one run}
        {--min-age=2 : Skip messages sent less than this many minutes ago}
        {--max-age=2880 : Skip messages sent more than this many minutes ago}
        {--chunk=50 : Message ids per /getmultistatus call}';

    protected $description = 'Poll the SMS gateway for delivery receipts on messages still marked sent';

    public function handle(ReveSmsClient $client, RecordDeliveryReceipt $recorder): int
    {
        if (! ReveSmsClient::isConfigured()) {
            $this->components->warn('SMS is not configured (missing: '.implode(', ', ReveSmsClient::missingCredentials()).') — nothing to poll.');

            return self::SUCCESS;
        }

        $pending = Notification::query()
            ->where('channel', 'sms')
            ->where('status', 'sent')
            ->where('provider', 'revesms')
            ->whereNotNull('provider_message_id')
            ->where('sent_at', '<=', now()->subMinutes((int) $this->option('min-age')))
            ->where('sent_at', '>=', now()->subMinutes((int) $this->option('max-age')))
            ->orderBy('sent_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($pending->isEmpty()) {
            $this->components->info('No SMS awaiting a delivery receipt.');

            return self::SUCCESS;
        }

        $settled = 0;
        $failures = 0;

        foreach ($pending->chunk(max(1, (int) $this->option('chunk'))) as $chunk) {
            /** @var Collection<int, Notification> $chunk */
            $byMessageId = $chunk->keyBy(fn (Notification $n): string => (string) $n->provider_message_id);

            try {
                $statuses = $client->multiStatus($byMessageId->keys()->all());
            } catch (Throwable $e) {
                // One unreachable chunk must not abandon the rest — the next
                // scheduled run picks these up again regardless.
                $this->components->error('Gateway call failed: '.$e->getMessage());
                $failures++;

                continue;
            }

            foreach ($statuses as $messageId => $status) {
                $notification = $byMessageId->get($messageId);

                if ($notification === null || $status->state === ReveSmsDeliveryState::PENDING) {
                    continue;
                }

                if ($recorder->execute(
                    notification: $notification,
                    state: $status->state,
                    providerStatus: $status->providerStatus,
                    rawPayload: $status->raw,
                )) {
                    $settled++;
                }
            }
        }

        $this->components->twoColumnDetail('Polled', (string) $pending->count());
        $this->components->twoColumnDetail('Settled', (string) $settled);

        if ($failures > 0) {
            $this->components->twoColumnDetail('Failed chunks', (string) $failures);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
