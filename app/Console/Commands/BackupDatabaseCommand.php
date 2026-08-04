<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * The provable half of Phase 9's "Encrypted backups with verified restore"
 * (docs/08 §Phase 9): this command produces the dump and proves — via
 * {@see RestoreDatabaseCommand}'s `--verify` mode — that it actually
 * restores. Encryption-at-rest and offsite replication need a real object
 * store and a key-management decision this environment doesn't have; they
 * are not built here, and this dump is gzip-compressed only, not encrypted.
 * Ship the gzip through your storage provider's server-side encryption (or
 * `gpg --encrypt` it) before it leaves this machine.
 *
 * Credentials never touch the process list (`ps aux` on a shared host would
 * otherwise leak `--password=...`): they go into a `[client]` section of a
 * `chmod 600` temp file passed via `--defaults-extra-file`, deleted in a
 * `finally` block. `MYSQL_PWD` (the other common shortcut) is deprecated by
 * MySQL itself for the same leakage reason and is deliberately not used.
 *
 * Alongside the dump, writes a `.meta.json` sidecar recording a per-table
 * row count at backup time — `RestoreDatabaseCommand --verify` restores into
 * a scratch database and diffs against exactly this file, not against
 * whatever the live database happens to contain when the restore runs
 * (which could be days later and legitimately different).
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'db:backup {--connection= : Database connection to dump (defaults to config(database.default))} {--path= : Output directory (defaults to storage/app/backups)}';

    protected $description = 'Dump the database to a gzip-compressed, checksummed file with a row-count manifest for later --verify restore';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'mysql') {
            $this->error("Connection [{$connection}] is not a configured mysql connection.");

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: storage_path('app/backups');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Could not create backup directory [{$directory}].");

            return self::FAILURE;
        }

        $database = $config['database'];
        $stamp = now()->format('Ymd\THis');
        $basename = "{$database}-{$stamp}";
        $dumpPath = "{$directory}/{$basename}.sql.gz";
        $metaPath = "{$directory}/{$basename}.meta.json";
        $checksumPath = "{$directory}/{$basename}.sql.gz.sha256";

        $credentialsFile = $this->writeCredentialsFile($config);

        try {
            $process = new Process([
                'mysqldump',
                "--defaults-extra-file={$credentialsFile}",
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.($config['port'] ?? '3306'),
                '--single-transaction',
                '--routines',
                '--triggers',
                '--set-gtid-purged=OFF',
                $database,
            ]);
            $process->setTimeout(null);
            $process->mustRun();
        } finally {
            @unlink($credentialsFile);
        }

        $written = file_put_contents($dumpPath, gzencode($process->getOutput(), 9));

        if ($written === false) {
            $this->error("Failed writing dump to [{$dumpPath}].");

            return self::FAILURE;
        }

        file_put_contents($checksumPath, hash('sha256', (string) file_get_contents($dumpPath))."  {$basename}.sql.gz\n");

        file_put_contents($metaPath, json_encode([
            'database' => $database,
            'connection' => $connection,
            'dumped_at' => now()->toIso8601String(),
            'table_row_counts' => $this->tableRowCounts($connection),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->info("Backed up [{$database}] to [{$dumpPath}] (".number_format(filesize($dumpPath) / 1024, 1).' KB, checksummed, manifest written).');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeCredentialsFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'db-backup-');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temp credentials file.');
        }

        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';

        file_put_contents($path, "[client]\nuser={$username}\npassword=\"{$password}\"\n");
        chmod($path, 0600);

        return $path;
    }

    /**
     * @return array<string, int>
     */
    private function tableRowCounts(string $connection): array
    {
        $database = config("database.connections.{$connection}.database");

        $tables = DB::connection($connection)->select(
            'select table_name as name from information_schema.tables where table_schema = ? and table_type = \'BASE TABLE\'',
            [$database]
        );

        $counts = [];

        foreach ($tables as $table) {
            $name = $table->name;
            $counts[$name] = (int) DB::connection($connection)->table($name)->count();
        }

        return $counts;
    }
}
