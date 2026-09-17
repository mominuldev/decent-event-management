<?php

namespace App\Domain\Ticketing\Actions;

use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\TicketNotificationPayload;
use InvalidArgumentException;

/**
 * Sends the ticket — QR code, ticket number, gate details — on the
 * channels an operator asks for.
 *
 * Since 2026-09-17 this is the **only** path that sends it. Issuance no
 * longer emails the QR; it emails a registration-confirmed message with
 * the share card (`QueueRegistrationConfirmedNotification`), and the
 * ticket goes out when staff send it from the admin console — one holder
 * from the ticket page, or every holder at once. The class and its routes
 * keep the `resend` name because the mechanism is unchanged and it does
 * still serve the "I never got my ticket" desk request: it composes the
 * message afresh, any number of times.
 *
 * Deliberately **not** `Notification\Actions\ResendNotification`, which
 * clones an existing outbox row and only from `failed`/`bounced`. That is
 * a different job: it retries a message this system already composed and
 * failed to deliver. This one composes the message afresh, so it works
 * whether or not a row exists — including for an attendee who had no
 * email address at issuance and has just given one.
 *
 * Composing afresh is also why it reads through
 * `TicketNotificationPayload`, shared with the registration-confirmed
 * listener: the two messages interpolate one set of variables rather than
 * two implementations of it.
 */
class ResendTicketNotification
{
    /** The template behind the ticket itself — QR, number, gate details. */
    public const string TEMPLATE_KEY = 'ticket_delivered';

    /**
     * Email and SMS only, while `whatsapp` still resolves to
     * `FakeWhatsAppDriver` (Meta template approval, External Dependencies).
     * This is an *operator action* — offering a button that fakes a send
     * would put `sent` in the delivery log for a message that never left
     * the building, and the operator would tell the ticket-holder it had.
     *
     * @var array<int, string>
     */
    public const array CHANNELS = ['email', 'sms'];

    /**
     * A confirmation says "you are in, here is your QR". Sending one for a
     * ticket that has been voided or refunded tells somebody they are
     * admitted when they are not, and hands them a QR the gate will
     * reject — so the status check is a correctness rule, not a tidiness
     * one.
     *
     * @var array<int, string>
     */
    public const array RESENDABLE_STATUSES = ['issued', 'active', 'partially_admitted', 'fully_admitted'];

    public function __construct(
        private readonly QueueNotification $queueNotification,
        private readonly TicketNotificationPayload $payload,
    ) {}

    /**
     * @param  array<int, string>  $channels
     * @return array<string, string> channel => `queued` | `no_recipient` | `no_template` | `duplicate`
     */
    public function execute(
        Ticket $ticket,
        array $channels,
        User $resentBy,
        ?string $ip = null,
        ?string $requestId = null,
        bool $auditIndividually = true,
    ): array {
        if (! in_array($ticket->status, self::RESENDABLE_STATUSES, true)) {
            throw new InvalidArgumentException(
                "A {$ticket->status} ticket cannot be resent — its QR no longer admits anyone."
            );
        }

        $attendee = $ticket->attendee;

        if ($attendee === null) {
            throw new InvalidArgumentException('This ticket has no attendee to send to.');
        }

        $channels = array_values(array_intersect($channels, self::CHANNELS));

        if ($channels === []) {
            throw new InvalidArgumentException('No sendable channel was requested.');
        }

        $outcomes = $this->queueNotification->execute(
            notifiable: $ticket,
            templateKey: self::TEMPLATE_KEY,
            channels: $channels,
            attendee: $attendee,
            payload: $this->payload->for($ticket),
            // Without a suffix the outbox's one-per-(subject, template,
            // channel) dedupe would swallow every send after the first
            // silently — the operator would get a 200 and the ticket-holder
            // would get nothing. The suffix is unique per send rather than
            // per second: two clicks are two messages, and on SMS two
            // charges, which is what the endpoint's Idempotency-Key is
            // there to prevent.
            dedupeSuffix: 'resend-'.now()->getTimestampMs().'-'.$resentBy->id,
        );

        // Logged from the Action (D8), so a bulk resend driven by a job
        // rather than a controller still leaves a trail. Records the
        // outcome per channel, not just the request: "queued email, no
        // mobile number on file" is the answer to the question this row
        // gets read to settle.
        //
        // A *batch* passes false and writes one summary row instead —
        // filters and counts, never the rows — the same choice the
        // attendee export makes. 12,000 near-identical entries would bury
        // every other event in the log, and the per-message trail already
        // exists in far more detail in the outbox itself, which records
        // each recipient, channel and delivery state.
        if (! $auditIndividually) {
            return $outcomes;
        }

        ActivityLog::create([
            'log_name' => 'ticket',
            'event' => 'notification_resent',
            'description' => "Resent ticket {$ticket->ticket_number} on ".implode(', ', $channels),
            'causer_type' => $resentBy->getMorphClass(),
            'causer_id' => $resentBy->id,
            'subject_type' => $ticket->getMorphClass(),
            'subject_id' => $ticket->id,
            'properties' => [
                'ticket_number' => $ticket->ticket_number,
                'channels' => $channels,
                'outcomes' => $outcomes,
            ],
            'ip_address' => $ip,
            'request_id' => $requestId,
        ]);

        return $outcomes;
    }
}
