<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Gateways\ReveSmsDeliveryState;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes one provider delivery receipt onto an outbox row — the single
 * place a `sent` notification becomes `delivered` or `bounced`.
 *
 * Both ways a receipt can arrive land here, so they cannot disagree: the
 * push callback REVE makes to `POST /webhooks/sms/dlr`, and the
 * `sms:poll-dlr` sweep that asks `/getmultistatus` for the same thing.
 * Whichever gets there first wins and the other becomes a no-op.
 *
 * Idempotent by state, not by a dedupe key: a repeated receipt for a
 * notification already in its target state records the event (the
 * timeline is append-only and a duplicate receipt is a real thing that
 * happened) and performs no transition. That matters because a carrier
 * genuinely re-sends receipts, and because a poll running alongside a
 * push will routinely see the same status twice.
 */
class RecordDeliveryReceipt
{
    /**
     * @param  array<string, mixed>  $rawPayload
     * @return bool whether the notification's own status changed
     */
    public function execute(
        Notification $notification,
        string $state,
        ?string $providerStatus = null,
        array $rawPayload = [],
        ?Carbon $occurredAt = null,
    ): bool {
        $occurredAt ??= now();

        return DB::transaction(function () use ($notification, $state, $providerStatus, $rawPayload, $occurredAt): bool {
            // Re-read under the transaction: a push and a poll arriving at
            // the same moment would otherwise both read `sent` and both
            // attempt the transition, and the loser throws
            // InvalidStateTransitionException out of a webhook.
            $fresh = Notification::query()->whereKey($notification->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return false;
            }

            $event = match ($state) {
                ReveSmsDeliveryState::DELIVERED => 'delivered',
                ReveSmsDeliveryState::FAILED => 'failed',
                default => 'status',
            };

            NotificationEvent::create([
                'notification_id' => $fresh->getKey(),
                'event' => $event,
                'provider_status' => $providerStatus !== null ? mb_substr($providerStatus, 0, 64) : null,
                'detail' => $this->detailFor($fresh, $state, $providerStatus),
                'raw_payload' => $rawPayload === [] ? null : $rawPayload,
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);

            $target = match ($state) {
                ReveSmsDeliveryState::DELIVERED => 'delivered',
                ReveSmsDeliveryState::FAILED => 'bounced',
                default => null,
            };

            // `pending` receipts (ACCEPTD, ENROUTE) are timeline entries and
            // nothing more — the message is still in flight.
            if ($target === null || ! $fresh->canTransitionTo($target)) {
                $notification->refresh();

                return false;
            }

            $fresh->transitionTo($target);

            if ($target === 'delivered') {
                $fresh->delivered_at = $occurredAt;
            } else {
                $fresh->failed_at = $occurredAt;
                $fresh->last_error = $this->detailFor($fresh, $state, $providerStatus);
            }

            $fresh->save();
            $notification->refresh();

            return true;
        });
    }

    private function detailFor(Notification $notification, string $state, ?string $providerStatus): string
    {
        $detail = sprintf('%s receipt for %s', $notification->channel, $notification->provider_message_id ?? 'unknown message');

        if ($providerStatus !== null && trim($providerStatus) !== '') {
            $detail .= ': '.trim($providerStatus);
        }

        return mb_substr($detail.' ('.$state.')', 0, 500);
    }
}
