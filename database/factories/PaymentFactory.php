<?php

namespace Database\Factories;

use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(50000, 500000);

        return [
            'payment_number' => 'PAY-100Y-'.fake()->unique()->numerify('######'),
            'registration_id' => Registration::factory(),
            'attendee_id' => Attendee::factory(),
            'method' => fake()->randomElement(['bkash', 'nagad', 'rocket', 'sslcommerz']),
            'channel' => 'online',
            'status' => 'initiated',
            'amount_due_paisa' => $amount,
            'amount_paid_paisa' => 0,
            'net_paisa' => 0,
            'gateway_transaction_id' => null,
            'initiated_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'paid_at' => null,
            'idempotency_key' => fake()->unique()->uuid(),
        ];
    }
}
