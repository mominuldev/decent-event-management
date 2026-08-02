<?php

namespace Database\Factories;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'type' => fake()->randomElement(['initiate', 'callback', 'ipn', 'verify']),
            'direction' => fake()->randomElement(['outbound', 'inbound']),
            'gateway' => fake()->randomElement(['bkash', 'nagad', 'rocket', 'sslcommerz']),
            'status' => 'success',
            'amount_paisa' => fake()->numberBetween(50000, 500000),
            'currency' => 'BDT',
            'gateway_transaction_id' => strtoupper(fake()->bothify('??########')),
            'signature_valid' => true,
            'latency_ms' => fake()->numberBetween(100, 3000),
        ];
    }
}
