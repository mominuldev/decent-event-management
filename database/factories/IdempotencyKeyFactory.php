<?php

namespace Database\Factories;

use App\Domain\Shared\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->uuid(),
            'scope' => fake()->randomElement(['payment.initiate', 'checkin.sync', 'webhook.bkash']),
            'request_hash' => hash('sha256', fake()->uuid()),
            'expires_at' => now()->addHours(24),
        ];
    }
}
