<?php

namespace Tests\Feature\Console;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-battery';

    /**
     * The case the command exists for: a live database that has only ever
     * had `migrate --force` run against it, so there is no RBAC catalogue
     * and syncRoles() would throw RoleDoesNotExist.
     */
    public function test_it_seeds_the_rbac_catalogue_when_the_role_does_not_exist_yet(): void
    {
        $this->assertDatabaseCount('roles', 0);

        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $this->assertTrue(
            Role::where('name', 'Super Admin')->where('guard_name', config('rbac.guard'))->exists()
        );

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('Super Admin'));
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        // Every permission in config/rbac.php, since the role is ['*'].
        $this->assertTrue($user->can('payment.refund'));
    }

    public function test_the_password_is_hashed_and_never_stored_in_the_clear(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $stored = (string) User::where('email', 'boss@example.com')->value('password');

        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored));
    }

    /**
     * The whole point of --force. Re-running the command to repair a role
     * must not silently reset the credentials of an account somebody is
     * already using.
     */
    public function test_it_refuses_to_overwrite_an_existing_password_without_force(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => 'a-completely-different-one',
        ])->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertTrue(Hash::check(self::PASSWORD, $user->password), 'the original password must survive');
        $this->assertFalse(Hash::check('a-completely-different-one', $user->password));
    }

    public function test_force_resets_the_password_and_clears_the_login_lockout(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        User::where('email', 'boss@example.com')->firstOrFail()->forceFill([
            'status' => 'suspended',
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ])->save();

        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => 'a-completely-different-one',
            '--force' => true,
        ])->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('a-completely-different-one', $user->password));
        $this->assertSame('active', $user->status);
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    /**
     * The repair path: an account that exists but never got its role, which
     * is what "the super admin is blank" looks like from the console.
     */
    public function test_it_assigns_the_role_to_an_existing_account_without_touching_the_password(): void
    {
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create([
            'email' => 'boss@example.com',
            'password' => self::PASSWORD,
            'status' => 'active',
        ]);
        $user->syncRoles([]);

        $this->artisan('admin:create-super-admin', ['--email' => 'boss@example.com'])
            ->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->hasRole('Super Admin'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_it_rejects_a_password_shorter_than_twelve_characters(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => 'short1234',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'boss@example.com']);
    }

    public function test_it_rejects_an_invalid_email_before_touching_anything(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--email' => 'not-an-address',
            '--password' => self::PASSWORD,
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
        // Nothing was seeded either — the email is checked first.
        $this->assertDatabaseCount('roles', 0);
    }

    /**
     * A soft-deleted staff account is deliberately deactivated. Quietly
     * restoring it on a plain re-run would undo that with no record.
     */
    public function test_a_soft_deleted_account_needs_force_to_come_back(): void
    {
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => self::PASSWORD]);
        $user->delete();

        $this->artisan('admin:create-super-admin', ['--email' => 'boss@example.com'])
            ->assertFailed();

        $this->assertSoftDeleted('users', ['email' => 'boss@example.com']);

        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => self::PASSWORD,
            '--force' => true,
        ])->assertSuccessful();

        $restored = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertNull($restored->deleted_at);
        $this->assertTrue($restored->hasRole('Super Admin'));
    }

    /**
     * Minting full system authority from shell access is exactly what the
     * audit trail is for, and it is the one account creation with no
     * authenticated actor behind it to attribute.
     */
    public function test_it_writes_an_audit_row_naming_the_console_as_the_source(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();
        $log = ActivityLog::where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->firstOrFail();

        $this->assertSame('user', $log->log_name);
        $this->assertSame('created', $log->event);
        $this->assertSame('warning', $log->severity);
        $this->assertNull($log->causer_id);
        $this->assertSame('console:admin:create-super-admin', $log->properties['source']);
        $this->assertNotNull($log->created_at);
    }

    public function test_the_name_and_phone_are_optional_and_default_sensibly(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertSame('Super Admin', $user->name);
        $this->assertNull($user->phone);

        $this->artisan('admin:create-super-admin', [
            '--email' => 'other@example.com',
            '--password' => self::PASSWORD,
            '--name' => 'Mominul Islam',
            '--phone' => '+8801711022299',
        ])->assertSuccessful();

        $other = User::where('email', 'other@example.com')->firstOrFail();

        $this->assertSame('Mominul Islam', $other->name);
        $this->assertSame('+8801711022299', $other->phone);
    }

    /**
     * Non-interactive is the deploy-script case: it must fail loudly rather
     * than block forever on a prompt nothing can answer.
     */
    public function test_it_fails_rather_than_prompting_when_there_is_no_password_and_no_terminal(): void
    {
        $this->artisan('admin:create-super-admin --email=boss@example.com --no-interaction')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'boss@example.com']);
    }

    /**
     * --if-missing is the deploy path: credentials come from the host's own
     * .env via config/admin.php, so nothing sensitive reaches the workflow
     * file or the SSH command line.
     */
    public function test_if_missing_creates_the_configured_account_on_a_migrate_only_database(): void
    {
        config()->set('admin.super_admin.email', 'boss@example.com');
        config()->set('admin.super_admin.password', self::PASSWORD);
        config()->set('admin.super_admin.name', 'Mominul Islam');
        config()->set('admin.super_admin.phone', '+8801711022299');

        $this->assertDatabaseCount('users', 0);

        $this->artisan('admin:create-super-admin --if-missing --no-interaction')
            ->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('Super Admin'));
        $this->assertSame('active', $user->status);
        $this->assertSame('Mominul Islam', $user->name);
        $this->assertSame('+8801711022299', $user->phone);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    /**
     * The default state of every environment that provisions its admin by
     * hand. A deploy must not invent an account for them.
     */
    public function test_if_missing_skips_quietly_when_nothing_is_configured(): void
    {
        $this->artisan('admin:create-super-admin --if-missing --no-interaction')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
        // Not even the RBAC catalogue: a no-op deploy step reseeds nothing.
        $this->assertDatabaseCount('roles', 0);
    }

    public function test_if_missing_skips_when_only_half_the_credentials_are_configured(): void
    {
        config()->set('admin.super_admin.email', 'boss@example.com');

        $this->artisan('admin:create-super-admin --if-missing --no-interaction')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * This runs on every single deploy. Re-granting the role here would hand
     * full authority back to an account somebody demoted on purpose, once
     * per release — so --if-missing writes nothing to an account that is
     * already there, not even the repair a plain re-run would do.
     */
    public function test_if_missing_leaves_an_existing_account_completely_alone(): void
    {
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create([
            'email' => 'boss@example.com',
            'password' => self::PASSWORD,
            'status' => 'suspended',
        ]);
        $user->syncRoles([]);

        config()->set('admin.super_admin.email', 'boss@example.com');
        config()->set('admin.super_admin.password', 'a-completely-different-one');

        $this->artisan('admin:create-super-admin --if-missing --no-interaction')
            ->assertSuccessful();

        $user->refresh();

        $this->assertFalse($user->hasRole('Super Admin'), 'a demoted account must stay demoted');
        $this->assertSame('suspended', $user->status);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_if_missing_leaves_a_soft_deleted_account_alone_without_failing_the_deploy(): void
    {
        $this->seed(RbacSeeder::class);

        User::factory()->create(['email' => 'boss@example.com', 'password' => self::PASSWORD])->delete();

        config()->set('admin.super_admin.email', 'boss@example.com');
        config()->set('admin.super_admin.password', self::PASSWORD);

        $this->artisan('admin:create-super-admin --if-missing --no-interaction')
            ->assertSuccessful();

        $this->assertSoftDeleted('users', ['email' => 'boss@example.com']);
    }

    /**
     * A blank setting is a decision and skips; a malformed one is a mistake
     * and must stop the deploy, or the release goes green having quietly
     * created no administrator at all.
     */
    public function test_if_missing_fails_on_a_configured_password_below_the_minimum(): void
    {
        config()->set('admin.super_admin.email', 'boss@example.com');
        config()->set('admin.super_admin.password', 'short1234');

        $this->artisan('admin:create-super-admin --if-missing --no-interaction')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'boss@example.com']);
    }

    public function test_if_missing_fails_on_a_configured_email_that_is_not_an_address(): void
    {
        config()->set('admin.super_admin.email', 'not-an-address');
        config()->set('admin.super_admin.password', self::PASSWORD);

        $this->artisan('admin:create-super-admin --if-missing --no-interaction')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_if_missing_and_force_are_refused_together(): void
    {
        config()->set('admin.super_admin.email', 'boss@example.com');
        config()->set('admin.super_admin.password', self::PASSWORD);

        $this->artisan('admin:create-super-admin --if-missing --force --no-interaction')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Re-running the deploy step is the normal case, not the exception.
     */
    public function test_if_missing_is_idempotent_across_repeated_deploys(): void
    {
        config()->set('admin.super_admin.email', 'boss@example.com');
        config()->set('admin.super_admin.password', self::PASSWORD);

        $this->artisan('admin:create-super-admin --if-missing --no-interaction')->assertSuccessful();
        $this->artisan('admin:create-super-admin --if-missing --no-interaction')->assertSuccessful();
        $this->artisan('admin:create-super-admin --if-missing --no-interaction')->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, ActivityLog::where('event', 'created')->count());
    }

    /**
     * The zero-configuration deploy path. Nothing is set anywhere: the email
     * falls back to config/admin.php's default and the password is invented,
     * so a first deploy produces a usable account with no prior setup.
     */
    public function test_generate_password_creates_the_account_with_nothing_configured(): void
    {
        config()->set('admin.super_admin.password', null);

        $this->assertDatabaseCount('users', 0);

        $this->artisan('admin:create-super-admin --if-missing --generate-password --no-interaction')
            ->assertSuccessful();

        $user = User::where('email', config('admin.super_admin.email'))->firstOrFail();

        $this->assertTrue($user->hasRole('Super Admin'));
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->password);
    }

    /**
     * The load-bearing assertion: the password printed in the deploy log is
     * the one that was actually hashed. Nothing can recover it afterwards, so
     * if the printed value and the stored hash ever disagreed the account
     * would be unreachable with no way to notice.
     */
    public function test_the_generated_password_is_printed_once_and_actually_works(): void
    {
        config()->set('admin.super_admin.password', null);

        Artisan::call('admin:create-super-admin --if-missing --generate-password --no-interaction');
        $printed = $this->passwordFrom(Artisan::output());

        $this->assertNotNull($printed, 'the generated password must be printed');
        $this->assertGreaterThanOrEqual(12, strlen($printed));

        $user = User::where('email', config('admin.super_admin.email'))->firstOrFail();

        $this->assertTrue(Hash::check($printed, $user->password));
        $this->assertNotSame($printed, $user->password, 'it must be stored hashed, not in the clear');
    }

    /**
     * "Once on deploy, never again" is the whole requirement: every later
     * release must find the account and leave it entirely alone, including
     * a password the owner has since changed.
     */
    public function test_a_second_deploy_neither_recreates_the_account_nor_prints_anything(): void
    {
        config()->set('admin.super_admin.password', null);

        Artisan::call('admin:create-super-admin --if-missing --generate-password --no-interaction');
        $first = $this->passwordFrom(Artisan::output());
        $this->assertNotNull($first);

        // The owner signs in and changes it, as the printed warning tells them to.
        $user = User::where('email', config('admin.super_admin.email'))->firstOrFail();
        $user->forceFill(['password' => 'a-password-they-chose'])->save();

        Artisan::call('admin:create-super-admin --if-missing --generate-password --no-interaction');
        $second = Artisan::output();

        $this->assertNull($this->passwordFrom($second), 'a later deploy must not print a new password');
        $this->assertStringContainsString('already exists', $second);

        $this->assertDatabaseCount('users', 1);
        $user->refresh();
        $this->assertTrue(Hash::check('a-password-they-chose', $user->password), 'their password must survive');
        $this->assertFalse(Hash::check($first, $user->password));
    }

    public function test_a_configured_password_wins_over_generating_one(): void
    {
        config()->set('admin.super_admin.email', 'boss@example.com');
        config()->set('admin.super_admin.password', self::PASSWORD);

        Artisan::call('admin:create-super-admin --if-missing --generate-password --no-interaction');
        $output = Artisan::output();

        $this->assertNull($this->passwordFrom($output), 'nothing was generated, so nothing may be printed');

        $user = User::where('email', 'boss@example.com')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    /** The value on the line after the banner's headline, or null if none was printed. */
    private function passwordFrom(string $output): ?string
    {
        $lines = preg_split('/\R/', $output) ?: [];

        foreach ($lines as $i => $line) {
            if (! str_contains($line, 'Generated password for')) {
                continue;
            }

            foreach (array_slice($lines, $i + 1) as $candidate) {
                if (trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return null;
    }

    public function test_the_created_account_can_actually_log_in(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--email' => 'boss@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $response = $this->postJson(route('api.v1.admin.auth.login'), [
            'email' => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['roles']]);
        $this->assertContains('Super Admin', $response->json('user.roles'));
    }
}
