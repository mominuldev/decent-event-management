<?php

namespace Database\Factories;

use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Models\RegistrationGuest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationGuest>
 */
class RegistrationGuestFactory extends Factory
{
    protected $model = RegistrationGuest::class;

    public function definition(): array
    {
        $ageGroup = fake()->randomElement(['adult', 'child']);

        return [
            'registration_id' => Registration::factory(),
            'full_name' => fake()->name(),
            'relation' => fake()->randomElement(['spouse', 'son', 'daughter', 'parent', 'guest']),
            'age_group' => $ageGroup,
            'age' => $ageGroup === 'child' ? fake()->numberBetween(1, 17) : fake()->numberBetween(18, 70),
            'gender' => fake()->randomElement(['male', 'female']),
            'tshirt_required' => fake()->boolean(70),
            'tshirt_size' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL']),
        ];
    }
}
