<?php

namespace Database\Factories;

use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'report_key' => fake()->randomElement(['registrations_by_batch', 'revenue_summary', 'tshirt_production', 'attendance_by_gate']),
            'format' => fake()->randomElement(['pdf', 'xlsx', 'csv']),
            'status' => 'completed',
            'requested_by_user_id' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }
}
