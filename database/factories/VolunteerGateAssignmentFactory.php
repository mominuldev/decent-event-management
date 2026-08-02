<?php

namespace Database\Factories;

use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerGateAssignment;
use App\Domain\CheckIn\Models\VolunteerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VolunteerGateAssignment>
 */
class VolunteerGateAssignmentFactory extends Factory
{
    protected $model = VolunteerGateAssignment::class;

    public function definition(): array
    {
        return [
            'volunteer_profile_id' => VolunteerProfile::factory(),
            'gate_id' => Gate::factory(),
        ];
    }
}
