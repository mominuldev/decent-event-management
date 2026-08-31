<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recovers the one migration failure that bricks every subsequent deploy.
 *
 * `2026_08_02_195127_create_permission_tables` (Spatie's) ends its up()
 * with a cache flush:
 *
 *     app('cache')->store(...)->forget(config('permission.cache.key'));
 *
 * That is the *last* statement, after all five Schema::create calls. If the
 * configured cache store is unreachable — a .env naming redis on a host with
 * no redis, which is the shape shared hosting arrives in — it throws there.
 * MySQL does not roll DDL back and Laravel records a migration only after
 * up() returns, so the five tables survive with no `migrations` row naming
 * them. Every later run then dies on
 *
 *     SQLSTATE[42S01]: Base table or view already exists: 1050
 *
 * and the database cannot move forward again without intervention.
 *
 * The repair is to record the migration, not to drop and re-create the
 * tables. Reaching the cache flush at all means every create already
 * succeeded, so the schema is complete and there is nothing to rebuild —
 * and recording touches no table anybody's data lives in, which dropping
 * cannot promise. `--force`-style destruction stays where it was: an
 * explicit, human-triggered `reset_database` dispatch.
 *
 * The completeness claim is checked rather than assumed. All five tables
 * present is exactly the condition "up() ran past its last create", so:
 *
 * - all five present, migration unrecorded -> record it, this is the bug
 * - none present, migration unrecorded     -> a normal pending migration
 * - some present                           -> real corruption. Refuse and
 *                                             name them; a partial schema
 *                                             recorded as applied is a
 *                                             worse failure, further away
 *                                             from the cause
 *
 * Safe to run on every deploy: it is a no-op on a healthy database, and the
 * prevention lives beside the caller — the deploy runs `migrate` with an
 * in-memory cache store so that final forget() can no longer reach anything
 * that is able to fail.
 */
class RepairPermissionTablesMigration extends Command
{
    protected $signature = 'migrate:repair-permission-tables
        {--dry-run : Report what would be recorded without writing anything}';

    protected $description = 'Record the permission-tables migration when its tables exist but it was never recorded';

    private const string MIGRATION = '2026_08_02_195127_create_permission_tables';

    public function handle(): int
    {
        $migrationsTable = $this->migrationsTable();

        if (! Schema::hasTable($migrationsTable)) {
            $this->components->info("No {$migrationsTable} table yet — nothing to repair.");

            return self::SUCCESS;
        }

        if (DB::table($migrationsTable)->where('migration', self::MIGRATION)->exists()) {
            $this->components->info(self::MIGRATION.' is already recorded — nothing to repair.');

            return self::SUCCESS;
        }

        $tables = $this->permissionTables();

        if ($tables === []) {
            $this->components->error('config/permission.php declares no table_names; cannot check anything.');

            return self::FAILURE;
        }

        $present = array_values(array_filter($tables, static fn (string $t): bool => Schema::hasTable($t)));
        $missing = array_values(array_diff($tables, $present));

        if ($present === []) {
            $this->components->info(
                self::MIGRATION.' is pending and none of its tables exist — that is a normal first run, '
                .'not the half-applied state. Leaving it to migrate.'
            );

            return self::SUCCESS;
        }

        if ($missing !== []) {
            $this->components->error(
                self::MIGRATION.' is half-applied in a way this cannot repair: '
                .count($present).' of '.count($tables).' tables exist ('.implode(', ', $present).') and '
                .implode(', ', $missing).' '.(count($missing) === 1 ? 'is' : 'are').' missing.'
            );
            $this->components->warn(
                'Recording it now would declare a schema applied that is not. Drop the tables that do exist '
                .'and re-run the migration, or use the reset_database dispatch if the database holds no real data.'
            );

            return self::FAILURE;
        }

        $this->components->warn(
            self::MIGRATION.' created all '.count($tables).' of its tables but was never recorded — the '
            .'cache flush at the end of its up() threw. Recording it so migrations can move forward.'
        );

        if ($this->option('dry-run')) {
            $this->components->info('--dry-run: nothing written.');

            return self::SUCCESS;
        }

        // The same batch as the migrations it ran alongside: those tables were
        // created during that run, and a batch of its own would imply it was
        // applied later than it was.
        $batch = max(1, (int) DB::table($migrationsTable)->max('batch'));

        DB::table($migrationsTable)->insert([
            'migration' => self::MIGRATION,
            'batch' => $batch,
        ]);

        $this->components->info('Recorded '.self::MIGRATION." in batch {$batch}.");

        return self::SUCCESS;
    }

    /**
     * `database.migrations` is an array in Laravel 11+ and was a plain string
     * before it; read both rather than assuming this app's shape survives an
     * upgrade.
     */
    private function migrationsTable(): string
    {
        $config = config('database.migrations');

        if (is_array($config)) {
            $table = $config['table'] ?? 'migrations';

            return is_string($table) ? $table : 'migrations';
        }

        return is_string($config) ? $config : 'migrations';
    }

    /**
     * Read from config rather than hardcoded, so a deployment that renamed a
     * permission table is checked against the names it actually uses.
     *
     * @return list<string>
     */
    private function permissionTables(): array
    {
        $names = config('permission.table_names');

        if (! is_array($names)) {
            return [];
        }

        return array_values(array_filter($names, 'is_string'));
    }
}
