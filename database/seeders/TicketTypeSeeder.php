<?php

namespace Database\Seeders;

use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Database\Seeder;

/**
 * The seven ticket-type codes referenced throughout docs/03 §3.7.
 */
class TicketTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'ALM', 'name' => 'Alumni', 'base_admits' => 1, 'max_admits' => 1, 'base_price_paisa' => 150000, 'allowed_participant_types' => ['former_student'], 'quantity_total' => 8000],
            ['code' => 'STU', 'name' => 'Current Student', 'base_admits' => 1, 'max_admits' => 1, 'base_price_paisa' => 50000, 'allowed_participant_types' => ['current_student'], 'quantity_total' => 3000],
            ['code' => 'TCH', 'name' => 'Teacher', 'base_admits' => 1, 'max_admits' => 1, 'base_price_paisa' => 0, 'allowed_participant_types' => ['teacher'], 'quantity_total' => 200],
            ['code' => 'STF', 'name' => 'Staff', 'base_admits' => 1, 'max_admits' => 1, 'base_price_paisa' => 0, 'allowed_participant_types' => ['staff'], 'quantity_total' => 200],
            ['code' => 'VIP', 'name' => 'VIP Guest', 'base_admits' => 2, 'max_admits' => 2, 'base_price_paisa' => 500000, 'allowed_participant_types' => ['guest'], 'quantity_total' => 200, 'requires_approval' => true, 'is_public' => false],
            ['code' => 'FAM', 'name' => 'Family', 'base_admits' => 4, 'max_admits' => 6, 'base_price_paisa' => 400000, 'additional_adult_price_paisa' => 100000, 'additional_child_price_paisa' => 50000, 'allowed_participant_types' => ['former_student', 'current_student', 'teacher', 'staff'], 'quantity_total' => 4000],
            ['code' => 'SPN', 'name' => 'Sponsor', 'base_admits' => 2, 'max_admits' => 4, 'base_price_paisa' => 1000000, 'allowed_participant_types' => ['sponsor'], 'quantity_total' => 100, 'requires_approval' => true, 'is_public' => false],
        ];

        foreach ($types as $i => $type) {
            TicketType::updateOrCreate(
                ['code' => $type['code']],
                array_merge([
                    'currency' => 'BDT',
                    'includes_meal' => true,
                    'is_active' => true,
                    'is_public' => true,
                    'sort_order' => $i,
                ], $type)
            );
        }
    }
}
