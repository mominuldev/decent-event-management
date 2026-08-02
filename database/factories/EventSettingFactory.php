<?php

namespace Database\Factories;

use App\Domain\Shared\Models\EventSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSetting>
 */
class EventSettingFactory extends Factory
{
    protected $model = EventSetting::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2, '.'),
            'group' => fake()->randomElement(['event', 'registration', 'payment', 'notification', 'checkin', 'branding']),
            'value' => fake()->word(),
            'type' => 'string',
            'is_encrypted' => false,
            'is_public' => false,
            'label' => fake()->words(3, true),
        ];
    }
}
