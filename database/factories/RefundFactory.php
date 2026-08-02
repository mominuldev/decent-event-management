<?php

namespace Database\Factories;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Registration\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'refund_number' => 'RFD-100Y-'.fake()->unique()->numerify('#####'),
            'payment_id' => Payment::factory(),
            'registration_id' => Registration::factory(),
            'amount_paisa' => fake()->numberBetween(10000, 500000),
            'reason' => fake()->sentence(),
            'type' => fake()->randomElement(['full', 'partial']),
            'method' => 'gateway',
            'status' => 'completed',
        ];
    }
}
