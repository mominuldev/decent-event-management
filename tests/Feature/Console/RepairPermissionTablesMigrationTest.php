<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * DatabaseMigrations, not RefreshDatabase: these tests drop and re-create
 * real tables, and MySQL commits implicitly on DDL — which would end
 * RefreshDatabase's wrapping transaction underneath every later test in the
 * process. Same reason DatabaseBackupRestoreTest uses it.
 */
class RepairPermissionTablesMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const string MIGRATION = '2026_08_02_195127_create_permission_tables';

    /** Child-first, or the foreign keys refuse the drop. */
    private const array TABLES_CHILD_FIRST = [
        'role_has_permissions',
        'model_has_roles',
        'model_has_permissions',
        'roles',
        'permissions',
    ];

    private function forgetTheMigrationRow(): void
    {
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
    }

    private function dropTables(int $howMany): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (array_slice(self::TABLES_CHILD_FIRST, 0, $howMany) as $table) {
            Schema::drop($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * The reported failure, reproduced end to end: the tables exist, the
     * migration is unrecorded, and `migrate` dies on 1050 every time until
     * the repair runs.
     */
    public function test_it_unblocks_a_migrate_that_dies_on_table_already_exists(): void
    {
        $this->forgetTheMigrationRow();

        $error = null;

        try {
            $this->artisan('migrate', ['--force' => true])->run();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertNotNull($error, 'migrate was expected to fail while the migration is unrecorded');
        $this->assertStringContainsString('already exists', $error);

        $this->artisan('migrate:repair-permission-tables')->assertSuccessful();

        // And now it moves forward instead of dying in the same place.
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    public function test_it_records_the_migration_when_every_table_is_present(): void
    {
        $this->forgetTheMigrationRow();

        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);

        $this->artisan('migrate:repair-permission-tables')->assertSuccessful();

        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
        $this->assertGreaterThanOrEqual(
            1,
            (int) DB::table('migrations')->where('migration', self::MIGRATION)->value('batch')
        );
    }

    /** Safe to run on every deploy is the whole point, so it must not double-record. */
    public function test_it_is_a_no_op_on_a_healthy_database(): void
    {
        $before = DB::table('migrations')->count();

        $this->artisan('migrate:repair-permission-tables')->assertSuccessful();

        $this->assertSame($before, DB::table('migrations')->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    /**
     * A genuinely pending migration — nothing created yet — must be left for
     * migrate to run, not declared applied.
     */
    public function test_it_leaves_a_genuinely_pending_migration_alone(): void
    {
        $this->forgetTheMigrationRow();
        $this->dropTables(5);

        $this->artisan('migrate:repair-permission-tables')->assertSuccessful();

        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    /**
     * The one case that must fail loudly: recording a partial schema as
     * applied moves the eventual error further from its cause.
     */
    public function test_it_refuses_when_only_some_of_the_tables_exist(): void
    {
        $this->forgetTheMigrationRow();
        $this->dropTables(1);

        $this->artisan('migrate:repair-permission-tables')->assertFailed();

        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->forgetTheMigrationRow();

        $this->artisan('migrate:repair-permission-tables --dry-run')->assertSuccessful();

        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }
}
