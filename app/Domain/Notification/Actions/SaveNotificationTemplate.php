<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use App\Domain\Shared\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Creates or edits a notification template from the admin console.
 *
 * Two things it does that a plain `update()` would not:
 *
 * **It records the segment count.** `estimated_segments` has existed on
 * this table since it was created and nothing ever wrote it. It matters
 * because an SMS is billed per segment and the boundary is invisible in
 * an editor: 160 characters is one segment, 161 is two, and a single
 * character outside GSM-7 — an emoji, or a plain `|` — drops the whole
 * message to 70 per segment and can triple the bill without changing a
 * visible word. Storing it means the templates list can show what each
 * message costs before anyone sends 12,000 of them.
 *
 * **It writes its own `ActivityLog` row** (D8 discipline: the Action logs,
 * not the controller). A template is the literal text sent to every
 * ticket-holder, so who changed the wording and when is worth keeping.
 * The before/after bodies are recorded — unlike a credential, the point
 * of this audit entry is exactly what the text used to say.
 */
class SaveNotificationTemplate
{
    /**
     * `$actor` is typed as a Model rather than a User because that is all
     * this needs and all `activity_logs` records — a morph class and a key.
     * Narrowing it to User would mean the caller casting the guard's return
     * value to satisfy a constraint the code does not actually have.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(?NotificationTemplate $template, array $data, ?Model $actor, ?string $requestId = null): NotificationTemplate
    {
        $creating = $template === null;
        $before = $creating ? null : $template->only(['subject', 'body', 'is_active', 'variables']);

        $template ??= new NotificationTemplate;

        $template->fill([
            // Identity is only settable on create. Moving an existing row to
            // a different (key, channel, locale) would silently retarget
            // every notification that uses it, and the unique index would
            // reject it as often as not — a new row is the honest way to
            // say "a different message".
            ...($creating ? [
                'key' => $data['key'],
                'channel' => $data['channel'],
                'locale' => $data['locale'],
                'version' => $data['version'] ?? 1,
            ] : []),
            'subject' => $data['subject'] ?? $template->subject,
            'body' => $data['body'],
            'is_active' => $data['is_active'] ?? ($template->is_active ?? true),
            'variables' => $data['variables'] ?? $template->variables ?? [],
        ]);

        // The column is unsigned, and segmentCount() never returns less
        // than 1 — max(0, …) states that for the type rather than trusting
        // it, since an unsigned column takes a negative as a database error
        // rather than a validation one.
        // Measured on the *rendered* body: `{` and `}` are not GSM-7, so a
        // raw template with placeholders always looks like Unicode and
        // over-counts by up to 3x. The column is unsigned, hence max(0, …).
        $template->estimated_segments = $template->channel === 'sms'
            ? max(0, SmsSegmentCalculator::segmentCount(
                SmsSegmentCalculator::renderForEstimate((string) $template->body),
            ))
            : null;

        $template->save();

        ActivityLog::create([
            'log_name' => 'notification_template',
            'event' => $creating ? 'created' : 'updated',
            'description' => sprintf(
                'Notification template %s (%s/%s) %s',
                $template->key,
                $template->channel,
                $template->locale,
                $creating ? 'created' : 'updated',
            ),
            'causer_type' => $actor?->getMorphClass(),
            'causer_id' => $actor?->getKey(),
            'subject_type' => $template->getMorphClass(),
            'subject_id' => $template->id,
            'properties' => [
                'old' => $before,
                'new' => $template->only(['subject', 'body', 'is_active', 'variables']),
            ],
            'ip_address' => request()->ip(),
            'request_id' => substr((string) ($requestId ?? Str::ulid()), 0, 26),
        ]);

        return $template;
    }
}
