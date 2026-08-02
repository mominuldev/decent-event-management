<?php

namespace Database\Factories;

use App\Domain\Registration\Models\Attendee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendee>
 */
class AttendeeFactory extends Factory
{
    protected $model = Attendee::class;

    public function definition(): array
    {
        $participantType = fake()->randomElement([
            'current_student', 'former_student', 'teacher', 'staff', 'guest', 'sponsor',
        ]);

        $needsBatchYear = in_array($participantType, ['current_student', 'former_student'], true);

        return [
            'full_name' => fake()->name(),
            'mobile' => '+8801'.fake()->numerify('#########'),
            'whatsapp_number' => fake()->boolean(30) ? '+8801'.fake()->numerify('#########') : null,
            'email' => fake()->boolean(70) ? fake()->safeEmail() : null,
            'gender' => fake()->randomElement(['male', 'female', 'other', 'prefer_not_to_say']),
            'date_of_birth' => fake()->boolean(60) ? fake()->dateTimeBetween('-70 years', '-15 years') : null,
            'occupation' => fake()->boolean(50) ? fake()->jobTitle() : null,
            'participant_type' => $participantType,
            'ssc_batch_year' => $needsBatchYear ? fake()->numberBetween(1971, 2024) : null,
            'current_class' => $participantType === 'current_student' ? fake()->randomElement(['9', '10']) : null,
            'tshirt_required' => fake()->boolean(70),
            'tshirt_size' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']),
            'address_district' => fake()->randomElement(['Dhaka', 'Chattogram', 'Sylhet', 'Rajshahi', 'Khulna', 'Barishal']),
            'country' => 'BD',
            'is_verified' => fake()->boolean(40),
        ];
    }
}
