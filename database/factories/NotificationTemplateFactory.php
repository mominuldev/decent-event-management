<?php

namespace Database\Factories;

use App\Domain\Notification\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2, '.'),
            'channel' => fake()->randomElement(['email', 'sms', 'whatsapp']),
            'locale' => fake()->randomElement(['en', 'bn']),
            'version' => 1,
            'subject' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'is_active' => true,
        ];
    }
}
