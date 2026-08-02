<?php

namespace Database\Seeders;

use App\Domain\CheckIn\Models\EventSession;
use App\Domain\CheckIn\Models\Gate;
use Illuminate\Database\Seeder;

/**
 * 6–10 gates per docs/08 §9.4 item 6 (default assumption pending client
 * confirmation of the real gate count).
 */
class GateSeeder extends Seeder
{
    public function run(): void
    {
        $session = EventSession::where('code', 'MAIN')->first();

        $gates = [
            ['code' => 'GATE-A', 'name' => 'Main Gate — Alumni'],
            ['code' => 'GATE-B', 'name' => 'Main Gate — Family'],
            ['code' => 'GATE-C', 'name' => 'Side Gate — Staff & Students'],
            ['code' => 'GATE-D', 'name' => 'VIP Gate'],
            ['code' => 'GATE-E', 'name' => 'North Gate'],
            ['code' => 'GATE-F', 'name' => 'South Gate'],
            ['code' => 'GATE-G', 'name' => 'Overflow Gate 1'],
            ['code' => 'GATE-H', 'name' => 'Overflow Gate 2'],
        ];

        foreach ($gates as $gate) {
            Gate::updateOrCreate(
                ['code' => $gate['code']],
                array_merge($gate, ['event_session_id' => $session?->id, 'is_active' => true])
            );
        }
    }
}
