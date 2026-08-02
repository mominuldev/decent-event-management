<?php

namespace Database\Factories;

use App\Domain\CheckIn\Models\Gate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gate>
 */
class GateFactory extends Factory
{
    protected $model = Gate::class;

    public function definition(): array
    {
        return [
            'code' => 'GATE-'.strtoupper(fake()->unique()->lexify('?')),
            'name' => fake()->word().' '.fake()->word().' Gate',
            'is_active' => true,
        ];
    }
}
