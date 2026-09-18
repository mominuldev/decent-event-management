<?php

namespace App\Console\Commands;

use Database\Seeders\Concerns\ContentSeedMode;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * Runs a page seeder additively: fields the seeder knows and the stored
 * blocks lack are written, and nothing already there is touched.
 *
 * A page seeder's normal re-run (`db:seed --class=TicketsPageSeeder`)
 * replaces every block's data with the seeded copy, which is right for a
 * fresh database and destructive on one an editor has been working in. When
 * a block type gains fields — the ticket card's own words joining
 * `ticket_pricing`, say — the live page needs those keys so the editor sees
 * the current copy rather than nine empty boxes over a card full of text,
 * and needs them without the rest of its edits being seeded over. This is
 * the middle path: the seeder's own block list, applied only where the
 * database has nothing.
 *
 * Only seeders using {@see SeedsContentBlocks} are accepted; anything else
 * would run in full and defeat the point.
 */
class BackfillSeededContentFields extends Command
{
    protected $signature = 'content:backfill-seeded-fields
        {seeder : The page seeder class, e.g. TicketsPageSeeder}';

    protected $description = 'Add fields a page seeder now knows to already-seeded blocks, leaving every existing value alone';

    public function handle(): int
    {
        $name = (string) $this->argument('seeder');
        $class = str_contains($name, '\\') ? $name : 'Database\\Seeders\\'.$name;

        if (! class_exists($class) || ! is_subclass_of($class, Seeder::class)) {
            $this->error("{$class} is not a seeder.");

            return self::FAILURE;
        }

        if (! in_array(SeedsContentBlocks::class, class_uses_recursive($class), true)) {
            $this->error("{$class} does not seed content blocks, so it has no additive mode — run it with db:seed instead.");

            return self::FAILURE;
        }

        ContentSeedMode::$fillMissingOnly = true;

        try {
            /** @var Seeder $seeder */
            $seeder = $this->laravel->make($class);
            $seeder->setContainer($this->laravel)->setCommand($this);
            $seeder->__invoke();
        } finally {
            ContentSeedMode::$fillMissingOnly = false;
        }

        $this->info("Backfilled missing block fields from {$class}. Existing values were left as they were.");

        return self::SUCCESS;
    }
}
