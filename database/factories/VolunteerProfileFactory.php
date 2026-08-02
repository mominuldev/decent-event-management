<?php

namespace Database\Factories;

use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<VolunteerProfile>
 */
class VolunteerProfileFactory extends Factory
{
    protected $model = VolunteerProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'volunteer_code' => 'VOL-'.fake()->unique()->numerify('###'),
            'pin_hash' => Hash::make('000000'),
            'pin_set_at' => now(),
            'team' => fake()->randomElement(['entry', 'registration_desk', 'vip']),
            'is_active' => true,
        ];
    }
}
