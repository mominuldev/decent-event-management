<?php

namespace Tests\Feature\Admin;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Database\Seeders\EventSettingSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Editing notification templates from the admin console.
 *
 * The cost assertions are the point of most of this. An SMS is billed per
 * segment per recipient, the boundary is invisible while typing, and the
 * cliffs are sharp enough that a single character can triple a bill
 * without changing a visible word.
 */
class NotificationTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role = 'Super Admin'): static
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['admin'], 'web-admin');

        return $this;
    }

    public function test_a_template_can_be_created(): void
    {
        $this->as()->postJson('/api/v1/admin/notifications/templates', [
            'key' => 'custom.reminder',
            'channel' => 'sms',
            'locale' => 'en',
            'body' => 'See you at {{event_name}} on {{event_date}}.',
            'variables' => ['event_name', 'event_date'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'custom.reminder')
            ->assertJsonPath('data.estimated_segments', 1);
    }

    public function test_a_duplicate_key_channel_and_language_is_refused_with_a_field_error(): void
    {
        NotificationTemplate::factory()->create([
            'key' => 'custom.reminder', 'channel' => 'sms', 'locale' => 'en', 'version' => 1,
        ]);

        // The table's unique index would otherwise be hit raw, which is a
        // 500 with a SQL error in the log rather than something an editor
        // can act on.
        $this->as()->postJson('/api/v1/admin/notifications/templates', [
            'key' => 'custom.reminder', 'channel' => 'sms', 'locale' => 'en', 'body' => 'Hello',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.key.0', fn (string $m): bool => str_contains($m, 'already exists'));
    }

    public function test_the_body_can_be_edited(): void
    {
        $template = NotificationTemplate::factory()->create([
            'key' => 'ticket_delivered', 'channel' => 'sms', 'locale' => 'en', 'body' => 'old text',
        ]);

        $this->as()->patchJson("/api/v1/admin/notifications/templates/{$template->ulid}", [
            'body' => 'Ticket confirmed - {{event_name}}',
        ])
            ->assertOk()
            ->assertJsonPath('data.body', 'Ticket confirmed - {{event_name}}');

        $this->assertSame('Ticket confirmed - {{event_name}}', $template->refresh()->body);
    }

    public function test_the_identity_of_an_existing_template_cannot_be_changed(): void
    {
        $template = NotificationTemplate::factory()->create([
            'key' => 'ticket_delivered', 'channel' => 'sms', 'locale' => 'en',
        ]);

        // Retargeting a template would silently change which notification it
        // renders, and the unique index would reject it half the time. A
        // different message is a different row.
        $this->as()->patchJson("/api/v1/admin/notifications/templates/{$template->ulid}", [
            'key' => 'something_else',
            'channel' => 'email',
            'body' => 'Hello',
        ])->assertOk();

        $template->refresh();
        $this->assertSame('ticket_delivered', $template->key);
        $this->assertSame('sms', $template->channel);
    }

    public function test_the_segment_estimate_is_measured_on_the_rendered_body_not_the_raw_one(): void
    {
        // The trap this pins: `{` and `}` are not GSM-7 characters, so a raw
        // template containing any placeholder measures as Unicode at 70 per
        // segment. The seeded ticket confirmation measured 3 segments raw
        // and 1 rendered — a 3x over-count, in the direction that makes an
        // affordable message look unaffordable.
        $template = NotificationTemplate::factory()->create(['channel' => 'sms', 'locale' => 'en']);

        $this->as()->patchJson("/api/v1/admin/notifications/templates/{$template->ulid}", [
            'body' => "Ticket confirmed - {{event_name}}\nID: {{ticket_id}}\n{{event_date}}, {{event_time}}, {{venue}}\nQR ticket sent to your email. Keep it for entry.",
        ])
            ->assertOk()
            ->assertJsonPath('data.estimated_segments', 1);
    }

    public function test_an_emoji_or_a_pipe_pushes_a_message_to_unicode(): void
    {
        $body = 'Ticket confirmed for {{event_name}}';

        $plain = $this->as()->postJson('/api/v1/admin/notifications/templates/preview', ['body' => $body])
            ->assertOk()->json();

        // Both of these are easy to reach for and neither looks expensive.
        $withPipe = $this->postJson('/api/v1/admin/notifications/templates/preview', ['body' => $body.' | 9:00 AM'])
            ->assertOk()->json();
        $withEmoji = $this->postJson('/api/v1/admin/notifications/templates/preview', ['body' => '🎉 '.$body])
            ->assertOk()->json();

        $this->assertSame('GSM-7', $plain['encoding']);
        $this->assertSame(160, $plain['characters_per_segment']);
        $this->assertSame('Unicode', $withPipe['encoding']);
        $this->assertSame(70, $withPipe['characters_per_segment']);
        $this->assertSame('Unicode', $withEmoji['encoding']);
    }

    public function test_the_preview_reports_the_cost_across_a_recipient_count(): void
    {
        $this->seed(EventSettingSeeder::class);
        config(['services.revesms.cost_paisa_per_segment' => 36]);

        $this->as()->postJson('/api/v1/admin/notifications/templates/preview', [
            'body' => '🎉 Ticket Confirmed! Your ticket for {{event_name}} on {{event_date}} at {{event_time}} in {{venue}} is confirmed. QR sent to your email.',
            'recipients' => 12000,
        ])
            ->assertOk()
            ->assertJsonPath('encoding', 'Unicode')
            // The number that makes the difference visible before anyone
            // presses send on 12,000 messages.
            ->assertJsonPath('cost_paisa_total', fn (int $t): bool => $t > 0);
    }

    public function test_the_preview_substitutes_placeholders_so_the_estimate_is_not_flattered(): void
    {
        // `{{venue}}` is 9 characters; what it renders to rarely is.
        $short = $this->as()->postJson('/api/v1/admin/notifications/templates/preview', [
            'body' => str_repeat('a', 150).'{{venue}}',
        ])->assertOk()->json();

        $this->assertGreaterThan(150, $short['characters']);
        $this->assertSame(2, $short['segments']);
    }

    public function test_editing_a_template_is_recorded_in_the_activity_log(): void
    {
        $template = NotificationTemplate::factory()->create(['channel' => 'sms', 'body' => 'before']);

        $this->as()->patchJson("/api/v1/admin/notifications/templates/{$template->ulid}", ['body' => 'after'])
            ->assertOk();

        $log = ActivityLog::query()->where('log_name', 'notification_template')->sole();

        // A template is the literal text sent to every ticket-holder, so
        // what it used to say is exactly what this entry is for.
        $this->assertSame('updated', $log->event);
        $this->assertSame('before', $log->properties['old']['body']);
        $this->assertSame('after', $log->properties['new']['body']);
    }

    public function test_the_endpoints_need_the_manage_templates_permission(): void
    {
        $template = NotificationTemplate::factory()->create();

        $this->as('Volunteer')
            ->patchJson("/api/v1/admin/notifications/templates/{$template->ulid}", ['body' => 'nope'])
            ->assertForbidden();

        $this->postJson('/api/v1/admin/notifications/templates/preview', ['body' => 'x'])->assertForbidden();
    }

    public function test_the_list_exposes_the_body_and_the_variables_an_editor_needs(): void
    {
        $this->seed(NotificationTemplateSeeder::class);

        $body = $this->as()->getJson('/api/v1/admin/notifications/templates')->assertOk()->json('data');

        $ticketSms = collect($body)->firstWhere(fn (array $t): bool => $t['key'] === 'ticket_delivered' && $t['channel'] === 'sms' && $t['locale'] === 'en');

        // Without these the screen can only list template names — which is
        // why it was read-only, and why a template written against a
        // variable that does not exist shipped `{{event_name}}` to a real
        // person's phone.
        $this->assertNotNull($ticketSms);
        $this->assertStringContainsString('{{ticket_id}}', $ticketSms['body']);
        $this->assertContains('event_name', $ticketSms['variables']);
        $this->assertSame(1, $ticketSms['estimated_segments']);
    }
}
