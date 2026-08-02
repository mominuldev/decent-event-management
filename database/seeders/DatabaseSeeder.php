<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RbacSeeder::class,
            EventSettingSeeder::class,
            TicketTypeSeeder::class,
            EventSessionSeeder::class,
            GateSeeder::class,
        ]);

        $superAdmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@decent100.example',
        ]);
        $superAdmin->assignRole('Super Admin');
    }
}
