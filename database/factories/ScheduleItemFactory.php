<?php

namespace Database\Factories;

use App\Domain\Content\Models\ScheduleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleItem>
 */
class ScheduleItemFactory extends Factory
{
    protected $model = ScheduleItem::class;

    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 month', '+2 months');

        return [
            'title' => fake()->sentence(3),
            'title_bn' => 'অনুষ্ঠানসূচি',
            'description' => fake()->paragraph(),
            'description_bn' => 'অনুষ্ঠানের বিবরণ।',
            'speaker_name' => fake()->name(),
            'venue' => fake()->words(2, true),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+1 hour'),
            'position' => 0,
            'is_published' => true,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}
