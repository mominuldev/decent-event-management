<?php

namespace Database\Factories;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    protected $model = CheckIn::class;

    public function definition(): array
    {
        return [
            'client_scan_uuid' => (string) Str::uuid(),
            'ticket_id' => Ticket::factory(),
            'result' => 'admitted',
            'admitted_count' => 1,
            'signature_valid' => true,
            'scan_mode' => fake()->randomElement(['online', 'offline']),
            'is_manual_override' => false,
            'scanned_at' => now(),
        ];
    }
}
