<?php

namespace Tests\Feature;

use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DatabaseMigrations rather than RefreshDatabase deliberately, matching
 * Concurrency\PurchaseConcurrencyTest's reasoning: mysqldump/mysql are real
 * separate OS processes with their own MySQL connections, so they cannot see
 * rows created inside RefreshDatabase's uncommitted wrapping transaction —
 * the backup manifest (built in-process, same connection, same transaction)
 * would then disagree with what the dump itself actually contains.
 */
class DatabaseBackupRestoreTest extends TestCase
{
    use DatabaseMigrations;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = storage_path('framework/testing/backups-'.Str::random(8));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);

        parent::tearDown();
    }

    public function test_backup_then_verified_restore_round_trips_real_data(): void
    {
        User::factory()->count(3)->create();

        $this->artisan('db:backup', ['--path' => $this->backupDir])->assertExitCode(0);

        $files = File::glob("{$this->backupDir}/*.sql.gz");
        $this->assertCount(1, $files, 'Expected exactly one dump to be written.');

        $dump = $files[0];
        $this->assertFileExists(preg_replace('/\.sql\.gz$/', '.meta.json', $dump));
        $this->assertFileExists("{$dump}.sha256");

        // Prove the checksum actually matches the file it describes.
        $checksum = trim(explode(' ', (string) file_get_contents("{$dump}.sha256"))[0]);
        $this->assertSame($checksum, hash('sha256', (string) file_get_contents($dump)));

        $this->artisan('db:restore', [
            'file' => $dump,
            '--verify' => true,
        ])->assertExitCode(0);
    }

    public function test_verified_restore_fails_loudly_on_a_row_count_mismatch(): void
    {
        User::factory()->count(2)->create();

        $this->artisan('db:backup', ['--path' => $this->backupDir])->assertExitCode(0);
        $dump = File::glob("{$this->backupDir}/*.sql.gz")[0];

        // Simulate a manifest that no longer matches the dump it travels with.
        $metaPath = preg_replace('/\.sql\.gz$/', '.meta.json', $dump);
        $manifest = json_decode((string) file_get_contents($metaPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['table_row_counts']['users'] = 999999;
        file_put_contents($metaPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->artisan('db:restore', ['file' => $dump, '--verify' => true])
            ->assertExitCode(1);
    }

    public function test_restore_without_verify_or_force_refuses_to_touch_the_live_database(): void
    {
        $this->artisan('db:backup', ['--path' => $this->backupDir])->assertExitCode(0);
        $dump = File::glob("{$this->backupDir}/*.sql.gz")[0];

        $this->artisan('db:restore', ['file' => $dump])
            ->assertExitCode(1);
    }
}
