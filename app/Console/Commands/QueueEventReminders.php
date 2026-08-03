<?php

namespace App\Console\Commands;

use App\Domain\CheckIn\Models\EventSession;
use App\Domain\Notification\Actions\QueueNotification;
use App\Domain\Registration\Models\Registration;
use Illuminate\Console\Command;

/**
 * Queues T-7/T-1/T-0 event reminders (docs/01 §1.6 channel matrix). Each
 * window has its own `template_key`, so the outbox's unique dedupe
 * constraint (notifiable + template_key + channel) makes a same-day
 * re-run — a retried scheduler tick, a manual re-invocation — a no-op
 * rather than a double-send.
 */
class QueueEventReminders extends Command
{
    protected $signature = 'notifications:queue-event-reminders';

    protected $description = 'Queue T-7/T-1/T-0 event reminder notifications for confirmed registrations.';

    /** @var array<string, int> */
    private const array WINDOWS = [
        'event_reminder_t7' => 7,
        'event_reminder_t1' => 1,
        'event_reminder_t0' => 0,
    ];

    public function handle(QueueNotification $queueNotification): int
    {
        $sessions = EventSession::query()->where('is_active', true)->whereNotNull('starts_at')->get();

        foreach ($sessions as $session) {
            foreach (self::WINDOWS as $templateKey => $daysBefore) {
                if (! now()->isSameDay($session->starts_at->copy()->subDays($daysBefore))) {
                    continue;
                }

                $this->queueForSession($session, $templateKey, $queueNotification);
            }
        }

        return self::SUCCESS;
    }

    private function queueForSession(EventSession $session, string $templateKey, QueueNotification $queueNotification): void
    {
        Registration::query()
            ->where('event_session_id', $session->id)
            ->where('status', 'confirmed')
            ->with('attendee')
            ->chunkById(200, function ($registrations) use ($session, $templateKey, $queueNotification): void {
                foreach ($registrations as $registration) {
                    $attendee = $registration->attendee;

                    if ($attendee === null) {
                        continue;
                    }

                    $queueNotification->execute(
                        notifiable: $registration,
                        templateKey: $templateKey,
                        channels: ['email', 'sms', 'whatsapp'],
                        attendee: $attendee,
                        payload: [
                            'full_name' => $attendee->full_name,
                            'event_name' => $session->name,
                            'event_venue' => $session->venue,
                            'event_starts_at' => $session->starts_at->toDayDateTimeString(),
                        ],
                    );
                }

                // A 20,000-recipient reminder run must never spike the
                // `notifications` queue lane in one instant burst
                // (docs/01 §1.6: "staggered dispatch").
                usleep(20_000);
            });
    }
}
