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
            // Before ContentSeeder: that seeder's menu pass resolves menu
            // items to pages by slug, and `home` is created here.
            HomePageSeeder::class,
            ContentSeeder::class,
            NotificationTemplateSeeder::class,
            DummyDataSeeder::class,
        ]);

        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@decent100.example'],
            [
                'name' => 'Super Admin',
                'phone' => '+8801700000000',
                'password' => 'password',
                'status' => 'active',
            ]
        );
        $superAdmin->syncRoles(['Super Admin']);
    }
}
