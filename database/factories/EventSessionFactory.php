<?php

namespace Database\Factories;

use App\Domain\CheckIn\Models\EventSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSession>
 */
class EventSessionFactory extends Factory
{
    protected $model = EventSession::class;

    public function definition(): array
    {
        $starts = now()->addMonths(3)->setTime(10, 0);

        return [
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->words(3, true),
            'venue' => fake()->company().' Campus',
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours(10),
            'checkin_opens_at' => $starts->copy()->subHour(),
            'checkin_closes_at' => $starts->copy()->addHours(8),
            'capacity' => fake()->numberBetween(5000, 15000),
            'is_active' => true,
        ];
    }
}
