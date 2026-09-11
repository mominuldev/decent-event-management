<?php

namespace App\Jobs;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Actions\ResendTicketNotification;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Support\TicketListFilters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resends the ticket confirmation to every ticket matching a filter set.
 *
 * **On the `reports` lane, not `notifications`** — its first occupant.
 * This job does not send anything itself; it walks the roster and writes
 * outbox rows, each of which dispatches its own `SendNotificationJob` onto
 * `notifications`. Putting the walk on that same lane would let one bulk
 * resend hold a notification worker for minutes while the individual
 * messages it is creating queue up behind it, which is precisely the
 * latency-budget separation docs/01 §1.3 defines the lanes to prevent.
 * `reports` is the minutes-scale lane, with a 600s timeout and two
 * workers.
 *
 * **Not resumable.** The lane runs `tries: 1`, so a crash halfway leaves a
 * partial send, and re-running resends to everyone the first pass already
 * reached — the resend dedupe suffix is unique per attempt by design, so
 * nothing downstream will stop it. The summary row records how far it got.
 * That is a deliberate limit rather than an oversight: making it resumable
 * means persisting a cursor, and the honest answer for a 12,000-row send
 * is to check the delivery log before running it again.
 */
class ResendTicketNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $filters  the same filter set the admin list takes
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public readonly array $filters,
        public readonly array $channels,
        public readonly int $requestedByUserId,
        public readonly ?string $ip = null,
        public readonly ?string $requestId = null,
    ) {
        $this->onQueue('reports');
    }

    public function handle(ResendTicketNotification $resend): void
    {
        $user = User::find($this->requestedByUserId);

        if ($user === null) {
            return;
        }

        $tallies = ['tickets' => 0, 'queued' => 0, 'no_recipient' => 0, 'no_template' => 0, 'duplicate' => 0, 'errored' => 0];

        self::query($this->filters)
            ->with('attendee')
            // chunkById, not chunk: this walks tens of thousands of rows
            // while other requests are voiding and issuing tickets, and an
            // offset walk skips a row at every page boundary when the set
            // shifts underneath it.
            ->chunkById(200, function ($tickets) use ($resend, $user, &$tallies): void {
                foreach ($tickets as $ticket) {
                    $tallies['tickets']++;

                    try {
                        // One ticket's failure must not abandon the rest of
                        // the roster: a single attendee row with no
                        // attendee, or a template lookup that throws, would
                        // otherwise end a 12,000-message send at row 40 with
                        // no summary written.
                        $outcomes = $resend->execute(
                            ticket: $ticket,
                            channels: $this->channels,
                            resentBy: $user,
                            ip: $this->ip,
                            requestId: $this->requestId,
                            auditIndividually: false,
                        );

                        foreach ($outcomes as $outcome) {
                            $tallies[$outcome] = ($tallies[$outcome] ?? 0) + 1;
                        }
                    } catch (Throwable $e) {
                        $tallies['errored']++;
                        Log::warning('Bulk ticket resend skipped a ticket', [
                            'ticket_id' => $ticket->id,
                            'ticket_number' => $ticket->ticket_number,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        ActivityLog::create([
            'log_name' => 'ticket',
            'event' => 'notifications_bulk_resent',
            'description' => ($this->isHandPicked() ? 'Resent ' : 'Bulk-resent ')
                ."{$tallies['tickets']} ticket(s) on ".implode(', ', $this->channels),
            'causer_type' => $user->getMorphClass(),
            'causer_id' => $user->id,
            'subject_type' => null,
            'subject_id' => null,
            // Filters and counts, never the rows — the point is to know who
            // sent what to how many, not to copy every ticket-holder's
            // details into the audit table.
            //
            // A hand-picked send is the one case where the filter *is* the
            // list of tickets, and recording it is the point rather than a
            // leak: ULIDs identify tickets, carry no personal detail, and
            // are capped at TicketListFilters::MAX_ULIDS.
            'properties' => [
                'filters' => $this->filters,
                'channels' => $this->channels,
                'tallies' => $tallies,
            ],
            'ip_address' => $this->ip,
            'request_id' => $this->requestId,
        ]);
    }

    /** Whether this send was aimed at tickets an operator picked by hand. */
    private function isHandPicked(): bool
    {
        return is_array($this->filters['ulids'] ?? null) && $this->filters['ulids'] !== [];
    }

    /**
     * The tickets a bulk resend will touch — the admin list's own filters,
     * narrowed to statuses whose QR still admits someone.
     *
     * The status narrowing is applied **after** the caller's filters and
     * with `whereIn`, so asking for `status=voided` selects nothing rather
     * than overriding the rule. Shared with the preview endpoint, so the
     * number an operator is shown before confirming is the number that
     * gets sent to.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    public static function query(array $filters): Builder
    {
        return TicketListFilters::apply(Ticket::query(), $filters, sorted: false)
            ->whereIn('status', ResendTicketNotification::RESENDABLE_STATUSES);
    }
}
