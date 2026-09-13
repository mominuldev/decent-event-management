<?php

namespace Database\Seeders;

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
            // EventSettingSeeder::class,
            TicketTypeSeeder::class,
            // EventSessionSeeder::class,
            // GateSeeder::class,
            // Before ContentSeeder: that seeder's menu pass resolves menu
            // items to pages by slug, and `home` is created here.
            HomePageSeeder::class,
            HistoryPageSeeder::class,
            EventPageSeeder::class,
            GalleryPageSeeder::class,
            SouvenirPageSeeder::class,
            TicketsPageSeeder::class,
            AttendeesPageSeeder::class,
            FaqPageSeeder::class,
            ContactPageSeeder::class,
            SponsorsPageSeeder::class,
            AlumniPageSeeder::class,
            ContentSeeder::class,
            NotificationTemplateSeeder::class,
            // DummyDataSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
