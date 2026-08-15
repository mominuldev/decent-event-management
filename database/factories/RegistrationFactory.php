<?php

namespace Database\Factories;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    protected $model = Registration::class;

    public function definition(): array
    {
        $adults = fake()->numberBetween(1, 2);
        $children = fake()->numberBetween(0, 2);
        $subtotal = fake()->numberBetween(50000, 500000);

        return [
            'registration_number' => 'REG-100Y-'.fake()->unique()->numerify('######'),
            'attendee_id' => Attendee::factory(),
            'ticket_type_id' => TicketType::factory(),
            'participation_type' => $children > 0 ? 'family' : ($adults > 1 ? 'couple' : 'single'),
            'adults_count' => $adults,
            'children_count' => $children,
            // Billable children only — free infants are their own head count
            // (see the 2026_08_14_100000 migration). Defaulted to none so a
            // factory-built registration prices and admits identically to
            // before; use ->withInfants() to exercise the free-infant path.
            'infants_count' => 0,
            'status' => 'draft',
            'subtotal_paisa' => $subtotal,
            'discount_paisa' => 0,
            'total_paisa' => $subtotal,
            'source' => 'web',
            'ip_address' => inet_pton(fake()->ipv4()),
            'user_agent' => fake()->userAgent(),
        ];
    }

    /**
     * A party carrying free infants — they are never priced but must still be
     * admitted, so `admits_total` is adults + children + infants.
     */
    public function withInfants(int $count = 1): static
    {
        return $this->state(fn () => [
            'infants_count' => $count,
            'participation_type' => 'family',
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => 'confirmed',
            'submitted_at' => now()->subDays(fake()->numberBetween(1, 60)),
            'confirmed_at' => now()->subDays(fake()->numberBetween(0, 59)),
        ]);
    }
}
