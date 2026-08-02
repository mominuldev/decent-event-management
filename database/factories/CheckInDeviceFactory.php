<?php

namespace Database\Factories;

use App\Domain\CheckIn\Models\CheckInDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckInDevice>
 */
class CheckInDeviceFactory extends Factory
{
    protected $model = CheckInDevice::class;

    public function definition(): array
    {
        return [
            'device_code' => 'DEV-'.fake()->unique()->numerify('##'),
            'device_name' => fake()->word().' '.fake()->word().' Phone',
            'device_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'platform' => 'android',
            'status' => 'active',
            'enrolled_at' => now(),
        ];
    }
}
