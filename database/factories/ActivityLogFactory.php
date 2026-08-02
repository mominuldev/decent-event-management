<?php

namespace Database\Factories;

use App\Domain\Shared\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'log_name' => fake()->randomElement(['auth', 'registration', 'payment', 'ticket', 'checkin', 'system', 'security']),
            'event' => fake()->randomElement(['created', 'updated', 'deleted', 'login_failed', 'permission_denied']),
            'description' => fake()->sentence(),
            'causer_type' => 'user',
            'severity' => 'info',
        ];
    }
}
