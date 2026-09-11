<?php

namespace Tests\Feature\Admin;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Actions\ResendTicketNotification;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Support\TicketListFilters;
use App\Jobs\ResendTicketNotificationsJob;
use Database\Seeders\EventSettingSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Resending a ticket confirmation — the "I never got my ticket" counter
 * request — for one ticket, and for every ticket matching a filter set.
 *
 * The load-bearing cases here are the ones that cost money or mislead a
 * ticket-holder: that the outbox's dedupe does not silently swallow a
 * resend, that a voided ticket cannot have its confirmation sent again,
 * and that a bulk send cannot reach more people than the operator was
 * shown and agreed to.
 */
class TicketResendTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role = 'Super Admin'): User
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['admin'], 'web-admin');

        return $user;
    }

    private ?TicketType $ticketType = null;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $attendeeAttributes
     */
    private function ticket(array $attributes = [], array $attendeeAttributes = []): Ticket
    {
        $attendee = Attendee::factory()->create($attendeeAttributes + [
            'email' => 'holder'.fake()->unique()->numerify('#####').'@example.test',
            'mobile' => '+88017'.fake()->unique()->numerify('########'),
        ]);

        // One per test: `ticket_types.code` is unique.
        $ticketType = $this->ticketType ??= TicketType::factory()->create(['code' => 'CEN']);

        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);

        return Ticket::factory()->create($attributes + [
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'registration_id' => $registration->id,
            'status' => 'active',
        ]);
    }

    /**
     * Both resend routes carry `idempotent:`, so every call needs a key —
     * a resent SMS is billed, and a double-tapped button must not charge
     * twice.
     *
     * @param  array<string, mixed>  $body
     */
    private function send(string $url, array $body): TestResponse
    {
        return $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson($url, $body);
    }

    /* ------------------------------------------------------------ single */

    public function test_a_ticket_can_be_resent_by_email_and_sms(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $ticket = $this->ticket();

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", ['channels' => ['email', 'sms']])
            ->assertOk()
            ->assertJsonPath('data.outcomes.email', 'queued')
            ->assertJsonPath('data.outcomes.sms', 'queued');

        $this->assertSame(2, Notification::query()
            ->where('notifiable_id', $ticket->id)
            ->where('template_key', 'ticket_delivered')
            ->count());
    }

    /**
     * The whole reason `QueueNotification` grew a dedupe suffix. Without
     * one, the outbox's one-per-(subject, template, channel) rule — which
     * exists so a retried event cannot double-send — would swallow every
     * resend: the operator gets a 200 and the ticket-holder gets nothing.
     */
    public function test_a_resend_is_not_swallowed_by_the_original_send(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $ticket = $this->ticket();

        // Stand in for the automatic send at issuance.
        Notification::factory()->create([
            'notifiable_type' => $ticket->getMorphClass(),
            'notifiable_id' => $ticket->id,
            'attendee_id' => $ticket->attendee_id,
            'template_key' => 'ticket_delivered',
            'channel' => 'email',
            'status' => 'sent',
            'dedupe_key' => $ticket->getMorphClass().':'.$ticket->id.':ticket_delivered:email',
        ]);

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", ['channels' => ['email']])
            ->assertOk()
            ->assertJsonPath('data.outcomes.email', 'queued');

        $this->assertSame(2, Notification::query()
            ->where('notifiable_id', $ticket->id)
            ->where('channel', 'email')
            ->count());
    }

    /**
     * A confirmation says "you are in, here is your QR". Sending one for a
     * voided ticket tells somebody they are admitted when the gate will
     * turn them away.
     */
    public function test_a_voided_ticket_cannot_be_resent(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $ticket = $this->ticket(['status' => 'voided']);

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", ['channels' => ['email']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'resend_failed');

        $this->assertSame(0, Notification::query()->where('notifiable_id', $ticket->id)->count());
    }

    /**
     * `attendees.mobile` is NOT NULL and `email` is not, so email is the
     * channel that can genuinely have nobody to send to — an alumnus who
     * registered at a desk and gave a phone number only. Pressing resend
     * and being told "queued" when nothing was is the failure this
     * reports around.
     */
    public function test_a_holder_with_no_email_reports_the_reason_rather_than_failing_silently(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $ticket = $this->ticket([], ['email' => null]);

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", ['channels' => ['email', 'sms']])
            ->assertOk()
            ->assertJsonPath('data.outcomes.sms', 'queued')
            ->assertJsonPath('data.outcomes.email', 'no_recipient');
    }

    /** SMS is billed per segment, so the channel is never inherited from a default. */
    public function test_channels_are_required(): void
    {
        $this->as();
        $ticket = $this->ticket();

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channels');
    }

    public function test_whatsapp_is_refused_while_its_driver_is_a_fake(): void
    {
        $this->as();
        $ticket = $this->ticket();

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", ['channels' => ['whatsapp']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channels.0');
    }

    /**
     * The switch is enforced at send time by SendNotificationJob, not here
     * — the row is still queued, and the operator is told it will be
     * cancelled rather than watching it vanish.
     */
    public function test_a_disabled_channel_is_reported_back_to_the_operator(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $this->seed(EventSettingSeeder::class);
        EventSetting::query()->where('key', 'notification.email_enabled')->update(['value' => '0']);
        $ticket = $this->ticket();

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", ['channels' => ['email']])
            ->assertOk()
            ->assertJsonPath('data.outcomes.email', 'queued')
            ->assertJsonPath('data.channels_disabled', ['email']);
    }

    public function test_the_resend_is_audited_with_its_per_channel_outcome(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $user = $this->as();
        $ticket = $this->ticket([], ['email' => null]);

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", ['channels' => ['email', 'sms']])->assertOk();

        $log = ActivityLog::query()->where('event', 'notification_resent')->firstOrFail();
        $this->assertSame($user->id, $log->causer_id);
        $this->assertSame($ticket->ticket_number, $log->properties['ticket_number']);
        $this->assertSame('no_recipient', $log->properties['outcomes']['email']);
    }

    public function test_resending_needs_the_notification_resend_permission(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as('Volunteer');
        $ticket = $this->ticket();

        $this->send("/api/v1/admin/tickets/{$ticket->ulid}/resend", ['channels' => ['email']])
            ->assertForbidden();
    }

    /* -------------------------------------------------------------- bulk */

    public function test_the_preview_counts_only_tickets_whose_qr_still_admits_someone(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $this->ticket(['status' => 'active']);
        $this->ticket(['status' => 'fully_admitted']);
        $this->ticket(['status' => 'voided']);

        $this->getJson('/api/v1/admin/tickets/resend-preview')
            ->assertOk()
            ->assertJsonPath('data.tickets', 2)
            ->assertJsonPath('data.with_email', 2);
    }

    public function test_the_preview_costs_sms_against_holders_who_actually_have_a_number(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $this->seed(EventSettingSeeder::class);
        EventSetting::query()->where('key', 'sms.cost_paisa_per_segment')->update(['value' => '36']);
        $this->ticket();
        $this->ticket([], ['email' => null]);

        $response = $this->getJson('/api/v1/admin/tickets/resend-preview')->assertOk();

        $this->assertSame(2, $response->json('data.tickets'));
        // Email is the count that moves: `attendees.mobile` is NOT NULL, so
        // every holder has a number and only some have an address.
        $this->assertSame(1, $response->json('data.with_email'));
        $this->assertSame(2, $response->json('data.with_mobile'));

        $segments = $response->json('data.sms_segments_each');
        $this->assertGreaterThan(0, $segments, 'the seeded ticket SMS should measure at least one segment');
        $this->assertSame($segments * 36 * 2, $response->json('data.sms_cost_paisa_total'));
    }

    /**
     * Confirmed to fail against the first cut of this endpoint, which
     * queried "an active sms template for this key" and got whichever
     * locale MySQL returned first — the Bangla one, at two segments, for a
     * message `notifications.locales.sms` sends in English at one. A 2x
     * over-estimate, in the direction that makes an affordable send look
     * unaffordable. Found by calling the real endpoint, not by a test.
     */
    public function test_the_sms_estimate_measures_the_language_that_will_actually_be_sent(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        config(['notifications.locales.sms' => 'en']);
        $this->ticket();

        $english = NotificationTemplate::query()
            ->where('key', 'ticket_delivered')->where('channel', 'sms')->where('locale', 'en')
            ->firstOrFail();
        $bangla = NotificationTemplate::query()
            ->where('key', 'ticket_delivered')->where('channel', 'sms')->where('locale', 'bn')
            ->firstOrFail();

        $expected = SmsSegmentCalculator::segmentCount(SmsSegmentCalculator::renderForEstimate((string) $english->body));
        $wrong = SmsSegmentCalculator::segmentCount(SmsSegmentCalculator::renderForEstimate((string) $bangla->body));

        // The fixture only proves anything while the two differ — one Bangla
        // character drops the whole message from 160 characters per segment
        // to 70, so they should.
        $this->assertNotSame($expected, $wrong, 'the seeded en and bn ticket SMS should not cost the same');

        $this->getJson('/api/v1/admin/tickets/resend-preview')
            ->assertOk()
            ->assertJsonPath('data.sms_segments_each', $expected);
    }

    public function test_a_bulk_resend_queues_a_fan_out_for_the_tickets_the_filters_select(): void
    {
        Queue::fake();
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $this->ticket();
        $this->ticket();

        $this->send('/api/v1/admin/tickets/resend-all', [
            'channels' => ['email'],
            'expected_count' => 2,
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.tickets', 2);

        Queue::assertPushed(ResendTicketNotificationsJob::class, fn (ResendTicketNotificationsJob $job): bool => $job->channels === ['email'] && $job->queue === 'reports');
    }

    /**
     * The window between the preview and the confirm is small, but the cost
     * of getting it wrong is a billed message for every ticket issued while
     * the dialog sat open.
     */
    public function test_a_bulk_resend_is_refused_when_the_roster_moved_since_the_preview(): void
    {
        Queue::fake();
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $this->ticket();
        $this->ticket();

        $this->send('/api/v1/admin/tickets/resend-all', [
            'channels' => ['email'],
            'expected_count' => 1,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recipient_count_changed');

        Queue::assertNothingPushed();
    }

    public function test_a_bulk_resend_needs_the_broadcast_permission_not_merely_resend(): void
    {
        Queue::fake();
        $this->seed(NotificationTemplateSeeder::class);
        $this->as('Volunteer');
        $this->ticket();

        $this->send('/api/v1/admin/tickets/resend-all', ['channels' => ['email'], 'expected_count' => 1])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_fan_out_sends_to_every_matching_ticket_and_skips_voided_ones(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $user = $this->as();
        $a = $this->ticket();
        $b = $this->ticket();
        $voided = $this->ticket(['status' => 'voided']);

        (new ResendTicketNotificationsJob(
            filters: [],
            channels: ['email'],
            requestedByUserId: (int) $user->id,
        ))->handle(app(ResendTicketNotification::class));

        $this->assertSame(1, Notification::query()->where('notifiable_id', $a->id)->count());
        $this->assertSame(1, Notification::query()->where('notifiable_id', $b->id)->count());
        $this->assertSame(0, Notification::query()->where('notifiable_id', $voided->id)->count());
    }

    /**
     * Filters and counts, never the rows — the same choice the attendee
     * export makes. 12,000 near-identical entries would bury every other
     * event in the log, and the outbox already records each recipient.
     */
    public function test_the_fan_out_writes_one_summary_row_not_one_per_ticket(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $user = $this->as();
        $this->ticket();
        $this->ticket();

        (new ResendTicketNotificationsJob(
            filters: ['status' => 'active'],
            channels: ['email'],
            requestedByUserId: (int) $user->id,
        ))->handle(app(ResendTicketNotification::class));

        $this->assertSame(0, ActivityLog::query()->where('event', 'notification_resent')->count());

        $log = ActivityLog::query()->where('event', 'notifications_bulk_resent')->firstOrFail();
        $this->assertSame(2, $log->properties['tallies']['tickets']);
        $this->assertSame(2, $log->properties['tallies']['queued']);
        $this->assertSame('active', $log->properties['filters']['status']);
    }

    /* ------------------------------------------ a hand-picked selection */

    /**
     * The selection is just a narrower filter, so it reaches the same
     * preview, the same count confirmation and the same fan-out job — there
     * is no second code path that could disagree with the first about who
     * gets a message.
     */
    public function test_the_preview_prices_only_the_tickets_that_were_picked(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $picked = $this->ticket();
        $this->ticket();
        $this->ticket();

        $this->getJson('/api/v1/admin/tickets/resend-preview?ulids[]='.$picked->ulid)
            ->assertOk()
            ->assertJsonPath('data.tickets', 1)
            ->assertJsonPath('data.with_email', 1);
    }

    public function test_the_fan_out_sends_to_the_picked_tickets_and_to_nobody_else(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $user = $this->as();
        $picked = $this->ticket();
        $alsoPicked = $this->ticket();
        $notPicked = $this->ticket();

        (new ResendTicketNotificationsJob(
            filters: ['ulids' => [$picked->ulid, $alsoPicked->ulid]],
            channels: ['email'],
            requestedByUserId: (int) $user->id,
        ))->handle(app(ResendTicketNotification::class));

        $this->assertSame(1, Notification::query()->where('notifiable_id', $picked->id)->count());
        $this->assertSame(1, Notification::query()->where('notifiable_id', $alsoPicked->id)->count());
        $this->assertSame(0, Notification::query()->where('notifiable_id', $notPicked->id)->count());
    }

    /**
     * The one rule that makes the whole feature safe to hand an operator:
     * an empty selection sends to nobody. Present-but-empty has to mean
     * "none" rather than "no filter", or a client bug that posts a cleared
     * selection becomes a message — and an SMS charge — for the entire
     * roster.
     *
     * Only the JSON send can express this; a query string cannot carry an
     * empty array at all, so the preview simply never sees the case. That
     * asymmetry is covered rather than ignored: `expected_count` is what
     * catches a preview that counted the whole roster because the
     * selection vanished on the way, and the test below proves it.
     */
    public function test_an_empty_selection_sends_to_nobody_rather_than_to_everybody(): void
    {
        Queue::fake();
        $this->seed(NotificationTemplateSeeder::class);
        $user = $this->as();
        $this->ticket();
        $this->ticket();

        $this->send('/api/v1/admin/tickets/resend-all', [
            'channels' => ['email'],
            'ulids' => [],
            'expected_count' => 0,
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.tickets', 0);

        (new ResendTicketNotificationsJob(
            filters: ['ulids' => []],
            channels: ['email'],
            requestedByUserId: (int) $user->id,
        ))->handle(app(ResendTicketNotification::class));

        $this->assertSame(0, Notification::query()->count());
    }

    /**
     * A selection that went missing between the preview and the confirm —
     * dropped by a serializer, cleared by a stray render — would otherwise
     * turn a three-ticket send into a whole-roster one. The count the
     * operator agreed to is what refuses it.
     */
    public function test_a_selection_lost_on_the_way_is_refused_rather_than_sent_to_everyone(): void
    {
        Queue::fake();
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $this->ticket();
        $this->ticket();
        $this->ticket();

        $this->send('/api/v1/admin/tickets/resend-all', [
            'channels' => ['email'],
            // The operator was shown 1 — the selection is simply not here.
            'expected_count' => 1,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recipient_count_changed');

        Queue::assertNothingPushed();
    }

    /** A scalar is a malformed selection, not a one-ticket one. */
    public function test_a_selection_that_is_not_a_list_is_refused(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $ticket = $this->ticket();

        $this->getJson('/api/v1/admin/tickets/resend-preview?ulids='.$ticket->ulid)
            ->assertStatus(422)
            ->assertJsonValidationErrors('ulids');
    }

    /**
     * A selection composes with the filters rather than replacing them, so
     * there is no combination of parameters that reaches somebody who was
     * not picked — picking a voided ticket, or one outside the current
     * filter, selects nothing rather than overriding the rule.
     */
    public function test_a_selection_can_only_narrow_never_widen(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $user = $this->as();
        $active = $this->ticket();
        $voided = $this->ticket(['status' => 'voided']);
        $admitted = $this->ticket(['status' => 'fully_admitted']);
        // Inside the filter, outside the selection — the half that proves
        // the pick narrows rather than merely agreeing with the filter.
        $unpickedButActive = $this->ticket();

        (new ResendTicketNotificationsJob(
            // All three picked, but the filter admits only the active one.
            filters: ['status' => 'active', 'ulids' => [$active->ulid, $admitted->ulid, $voided->ulid]],
            channels: ['email'],
            requestedByUserId: (int) $user->id,
        ))->handle(app(ResendTicketNotification::class));

        $this->assertSame(1, Notification::query()->where('notifiable_id', $active->id)->count());
        $this->assertSame(0, Notification::query()->where('notifiable_id', $admitted->id)->count());
        $this->assertSame(0, Notification::query()->where('notifiable_id', $voided->id)->count());
        $this->assertSame(0, Notification::query()->where('notifiable_id', $unpickedButActive->id)->count());
    }

    public function test_a_picked_selection_still_has_to_match_the_count_the_operator_agreed_to(): void
    {
        Queue::fake();
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();
        $a = $this->ticket();
        $b = $this->ticket();

        // Voided between the preview and the confirm: the selection can only
        // ever shrink, but the operator still agreed to a number.
        $b->forceFill(['status' => 'voided'])->save();

        $this->send('/api/v1/admin/tickets/resend-all', [
            'channels' => ['email'],
            'ulids' => [$a->ulid, $b->ulid],
            'expected_count' => 2,
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'recipient_count_changed');

        Queue::assertNothingPushed();
    }

    public function test_more_tickets_than_the_cap_are_refused_by_both_the_preview_and_the_send(): void
    {
        Queue::fake();
        $this->seed(NotificationTemplateSeeder::class);
        $this->as();

        $tooMany = array_map(fn (): string => (string) Str::ulid(), range(1, TicketListFilters::MAX_ULIDS + 1));

        $this->getJson('/api/v1/admin/tickets/resend-preview?'.http_build_query(['ulids' => $tooMany]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ulids');

        $this->send('/api/v1/admin/tickets/resend-all', [
            'channels' => ['email'],
            'ulids' => $tooMany,
            'expected_count' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('ulids');

        Queue::assertNothingPushed();
    }

    /**
     * Recording the ULIDs is the point of a hand-picked send rather than a
     * leak — they identify tickets, carry no personal detail, and are
     * capped — where the filters-only case deliberately records no rows.
     */
    public function test_a_hand_picked_send_records_which_tickets_it_reached(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $user = $this->as();
        $picked = $this->ticket();
        $this->ticket();

        (new ResendTicketNotificationsJob(
            filters: ['ulids' => [$picked->ulid]],
            channels: ['email'],
            requestedByUserId: (int) $user->id,
        ))->handle(app(ResendTicketNotification::class));

        $log = ActivityLog::query()->where('event', 'notifications_bulk_resent')->firstOrFail();
        $this->assertSame([$picked->ulid], $log->properties['filters']['ulids']);
        $this->assertSame(1, $log->properties['tallies']['tickets']);
        $this->assertStringStartsWith('Resent 1 ticket', (string) $log->description);
    }
}
