<?php

namespace Database\Seeders;

use App\Domain\CheckIn\Models\EventSession;
use Illuminate\Database\Seeder;

/**
 * Single continuous-day event — docs/README open question 1. Collapses to
 * one row; multi-session support needs no schema change if that changes.
 */
class EventSessionSeeder extends Seeder
{
    public function run(): void
    {
        $starts = now()->addMonths(6)->setTime(9, 0);

        EventSession::updateOrCreate(['code' => 'MAIN'], [
            'name' => 'Main Event Day',
            'venue' => 'School Campus',
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours(11),
            'checkin_opens_at' => $starts->copy()->subHour(),
            'checkin_closes_at' => $starts->copy()->addHours(9),
            'capacity' => 20000,
            'is_active' => true,
        ]);
    }
}
