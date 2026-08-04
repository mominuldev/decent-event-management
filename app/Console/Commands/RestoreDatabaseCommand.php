<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Companion to {@see BackupDatabaseCommand}. Two distinct modes because they
 * carry very different risk:
 *
 * `--verify` is the drill this codebase can actually run today: restore the
 * dump into a throwaway scratch database (`{db}_verify_<random>`), diff its
 * per-table row counts against the `.meta.json` manifest captured at backup
 * time, then drop the scratch database — the live database is never opened
 * for writing. This is what proves a backup is restorable, not just that
 * `mysqldump` exited zero.
 *
 * Without `--verify`, this performs the real, destructive restore into the
 * connection's configured database — wiping whatever is there first, since
 * a dump replaying `CREATE TABLE` statements over live tables would
 * otherwise collide. Gated behind `--force` specifically so this can never
 * be an accidental flag order away from destroying data; there is no
 * confirmation prompt to click through by muscle memory during an incident.
 */
class RestoreDatabaseCommand extends Command
{
    protected $signature = 'db:restore
        {file : Path to a .sql.gz dump produced by db:backup}
        {--verify : Restore into a disposable scratch database, diff row counts against the backup manifest, then drop it. The real database is never touched}
        {--force : Required to actually overwrite the connection'."'".'s live database. Ignored (and unnecessary) with --verify}
        {--connection= : Database connection to restore into (defaults to config(database.default))}';

    protected $description = 'Restore a db:backup dump, either for real (--force) or as a disposable --verify drill';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("Dump file not found: [{$file}].");

            return self::FAILURE;
        }

        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'mysql') {
            $this->error("Connection [{$connection}] is not a configured mysql connection.");

            return self::FAILURE;
        }

        if ($this->option('verify')) {
            return $this->verifyRestore($file, $connection, $config);
        }

        if (! $this->option('force')) {
            $this->error('Refusing to overwrite the live database without --force. Use --verify to test a restore safely instead.');

            return self::FAILURE;
        }

        $this->restoreInto($file, $config, (string) $config['database']);
        $this->info("Restored [{$file}] into [{$config['database']}].");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function verifyRestore(string $file, string $connection, array $config): int
    {
        $metaPath = preg_replace('/\.sql\.gz$/', '.meta.json', $file);
        $manifest = is_string($metaPath) && is_file($metaPath)
            ? json_decode((string) file_get_contents($metaPath), true, flags: JSON_THROW_ON_ERROR)
            : null;

        if (! is_array($manifest) || ! is_array($manifest['table_row_counts'] ?? null)) {
            $this->error("No usable manifest found alongside [{$file}] — expected [{$metaPath}] from db:backup.");

            return self::FAILURE;
        }

        $scratch = $config['database'].'_verify_'.Str::lower(Str::random(8));

        DB::connection($connection)->statement("CREATE DATABASE `{$scratch}`");

        try {
            $this->restoreInto($file, $config, $scratch);

            $mismatches = [];

            foreach ($manifest['table_row_counts'] as $table => $expected) {
                $actual = (int) DB::connection($connection)
                    ->selectOne("SELECT COUNT(*) AS c FROM `{$scratch}`.`{$table}`")
                    ->c;

                if ($actual !== (int) $expected) {
                    $mismatches[] = "{$table}: expected {$expected}, restored {$actual}";
                }
            }

            if ($mismatches !== []) {
                $this->error('Restore verification FAILED:');
                foreach ($mismatches as $line) {
                    $this->line("  - {$line}");
                }

                return self::FAILURE;
            }

            $this->info(sprintf(
                'Restore verified: %d tables in [%s] match the backup manifest exactly.',
                count($manifest['table_row_counts']),
                $file,
            ));

            return self::SUCCESS;
        } finally {
            DB::connection($connection)->statement("DROP DATABASE `{$scratch}`");
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function restoreInto(string $file, array $config, string $targetDatabase): void
    {
        $credentialsFile = $this->writeCredentialsFile($config);
        $stream = gzopen($file, 'rb');

        if ($stream === false) {
            @unlink($credentialsFile);

            throw new RuntimeException("Could not open [{$file}] as gzip.");
        }

        try {
            $process = new Process([
                'mysql',
                "--defaults-extra-file={$credentialsFile}",
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.($config['port'] ?? '3306'),
                $targetDatabase,
            ]);
            $process->setTimeout(null);
            $process->setInput($stream);
            $process->mustRun();
        } finally {
            fclose($stream);
            @unlink($credentialsFile);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeCredentialsFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'db-restore-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temp credentials file.');
        }

        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';

        file_put_contents($path, "[client]\nuser={$username}\npassword=\"{$password}\"\n");
        chmod($path, 0600);

        return $path;
    }
}
