<?php

namespace Tests\Feature\Notification;

use App\Domain\CheckIn\Models\EventSession;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    private function activeTemplate(string $key, string $channel): void
    {
        NotificationTemplate::factory()->create([
            'key' => $key,
            'channel' => $channel,
            'locale' => 'en',
            'version' => 1,
            'body' => 'Reminder for {{full_name}}',
            'is_active' => true,
        ]);
    }

    public function test_queues_a_t7_reminder_for_a_confirmed_registration_in_the_window(): void
    {
        foreach (['email', 'sms', 'whatsapp'] as $channel) {
            $this->activeTemplate('event_reminder_t7', $channel);
        }

        $session = EventSession::factory()->create(['starts_at' => now()->addDays(7)->setTime(10, 0), 'is_active' => true]);
        $attendee = Attendee::factory()->create(['email' => 'attendee@example.com', 'mobile' => '8801711111111']);
        Registration::factory()->for($attendee)->create(['event_session_id' => $session->id, 'status' => 'confirmed']);

        Artisan::call('notifications:queue-event-reminders');

        $this->assertSame(3, Notification::where('template_key', 'event_reminder_t7')->count());
    }

    public function test_rerunning_the_command_the_same_day_does_not_double_queue(): void
    {
        $this->activeTemplate('event_reminder_t1', 'email');

        $session = EventSession::factory()->create(['starts_at' => now()->addDay()->setTime(10, 0), 'is_active' => true]);
        $attendee = Attendee::factory()->create(['email' => 'attendee@example.com']);
        Registration::factory()->for($attendee)->create(['event_session_id' => $session->id, 'status' => 'confirmed']);

        Artisan::call('notifications:queue-event-reminders');
        Artisan::call('notifications:queue-event-reminders');

        $this->assertSame(1, Notification::where('template_key', 'event_reminder_t1')->count());
    }

    public function test_registration_outside_any_window_gets_no_reminder(): void
    {
        $this->activeTemplate('event_reminder_t7', 'email');
        $this->activeTemplate('event_reminder_t1', 'email');
        $this->activeTemplate('event_reminder_t0', 'email');

        $session = EventSession::factory()->create(['starts_at' => now()->addDays(3)->setTime(10, 0), 'is_active' => true]);
        $attendee = Attendee::factory()->create(['email' => 'attendee@example.com']);
        Registration::factory()->for($attendee)->create(['event_session_id' => $session->id, 'status' => 'confirmed']);

        Artisan::call('notifications:queue-event-reminders');

        $this->assertSame(0, Notification::count());
    }

    public function test_non_confirmed_registration_is_skipped(): void
    {
        $this->activeTemplate('event_reminder_t0', 'email');

        $session = EventSession::factory()->create(['starts_at' => now()->setTime(10, 0), 'is_active' => true]);
        $attendee = Attendee::factory()->create(['email' => 'attendee@example.com']);
        Registration::factory()->for($attendee)->create(['event_session_id' => $session->id, 'status' => 'pending_payment']);

        Artisan::call('notifications:queue-event-reminders');

        $this->assertSame(0, Notification::count());
    }
}
