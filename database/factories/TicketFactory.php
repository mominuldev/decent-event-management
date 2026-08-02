<?php

namespace Database\Factories;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'ticket_number' => 'DEC100-ALM-'.fake()->year().'-'.fake()->unique()->numerify('#####'),
            'registration_id' => Registration::factory(),
            'attendee_id' => Attendee::factory(),
            'ticket_type_id' => TicketType::factory(),
            'status' => 'active',
            'admits_total' => fake()->numberBetween(1, 4),
            'admitted_count' => 0,
            'price_paid_paisa' => fake()->numberBetween(50000, 500000),
            'holder_name' => fake()->name(),
            'holder_batch_year' => fake()->numberBetween(1971, 2024),
            'holder_type_label' => 'Alumni',
            'issued_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }
}
