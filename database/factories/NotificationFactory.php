<?php

namespace Database\Factories;

use App\Domain\Notification\Models\Notification;
use App\Domain\Registration\Models\Attendee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        $attendeeId = Attendee::factory()->create()->id;

        return [
            'notifiable_type' => 'attendee',
            'notifiable_id' => $attendeeId,
            'attendee_id' => $attendeeId,
            'template_key' => fake()->randomElement(['registration.received', 'payment.succeeded', 'ticket.delivered']),
            'channel' => fake()->randomElement(['email', 'sms', 'whatsapp']),
            'locale' => 'en',
            'recipient' => fake()->safeEmail(),
            'status' => 'sent',
            'priority' => 3,
            'max_attempts' => 5,
            'sent_at' => now(),
        ];
    }
}
