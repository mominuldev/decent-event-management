<?php

namespace Database\Factories;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationEvent>
 */
class NotificationEventFactory extends Factory
{
    protected $model = NotificationEvent::class;

    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'event' => fake()->randomElement(['queued', 'sent', 'delivered', 'read']),
            'occurred_at' => now(),
        ];
    }
}
