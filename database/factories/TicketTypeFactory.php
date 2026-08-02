<?php

namespace Database\Factories;

use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(2, true),
            'base_price_paisa' => fake()->numberBetween(50000, 500000),
            'base_admits' => 1,
            'max_admits' => 1,
            'allowed_participant_types' => ['former_student', 'current_student'],
            'quantity_total' => fake()->numberBetween(500, 5000),
            'is_active' => true,
            'is_public' => true,
        ];
    }
}
