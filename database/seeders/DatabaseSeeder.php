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
            DummyDataSeeder::class,
        ]);

        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@decent100.example'],
            [
                'name' => 'Super Admin',
                'phone' => '+8801700000000',
                'password' => '$2y$12$eWzXh6zVlQ11.M1O8gXv8.5aN8g1r2A1t5N.1pW0l/Z1n2o3p4q5r',
                'status' => 'active',
            ]
        );
        $superAdmin->syncRoles(['Super Admin']);
    }
}
