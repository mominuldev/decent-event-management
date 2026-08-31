<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Creates the first Super Admin on a freshly deployed environment.
 *
 * There is no HTTP path for this and there deliberately is not one:
 * `routes/api/admin.php` exposes users index/show and assign-role only, so
 * every staff account is created by someone who already has one. That
 * leaves the very first account, which has to come from the console.
 *
 * `php artisan db:seed` is the wrong tool for it. DatabaseSeeder pulls in
 * DummyDataSeeder — demo registrations, payments and tickets — and creates
 * a hardcoded account whose password is literally `password`. Fine on a
 * laptop, and exactly what must never reach a live database.
 *
 * Three things this does that a tinker one-liner reliably gets wrong:
 *
 * 1. It seeds the RBAC catalogue first when the role is missing.
 *    `syncRoles(['Super Admin'])` throws RoleDoesNotExist against a
 *    database that has only ever had `migrate --force` run against it,
 *    which is every environment this repo's deploy workflow produces.
 * 2. It refuses to overwrite an existing password without `--force`, so
 *    re-running it to repair a *role* assignment cannot silently reset the
 *    credentials of a working account.
 * 3. It writes the audit row. Minting full system authority is exactly the
 *    kind of privileged action `activity_logs` exists to record, and a
 *    console-created account is the one that otherwise leaves no trace of
 *    where it came from.
 *
 * The password is prompted for rather than passed as an option by default:
 * an option lands in shell history and is visible in `ps` for the life of
 * the process, on a box that by definition has other people on it.
 * `--generate-password` is the third way: nobody types it, nothing stores
 * it but a bcrypt hash, and it is printed once at creation because after
 * that no code path can recover it. The cost is that it lands in whatever
 * log the command ran in, which is why the output says so in as many words
 * rather than leaving it to be inferred.
 *
 * `--if-missing` is the deploy path (wired into .github/workflows/deploy.yml),
 * and takes its credentials from config/admin.php — i.e. the host's own .env
 * — for that same reason: nothing sensitive reaches the workflow file, the
 * SSH command line, or `ps`. It is the narrowest of the three modes and
 * mutates nothing it did not create:
 *
 * - Nothing configured  -> skip, exit 0, unless --generate-password. An
 *   environment that provisions its admin by hand must not have one invented
 *   for it on every release; one that asked for a generated password has.
 * - Account exists      -> report and exit 0. Re-granting the role here
 *   would silently restore full authority to an account somebody demoted on
 *   purpose, once per deploy, which is the worst possible cadence for it.
 * - Configured but wrong (unparseable email, password under the minimum)
 *   -> fail. A blank setting is a decision; a malformed one is a mistake,
 *   and a green deploy that quietly created no administrator is exactly the
 *   failure this command was written to end.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create-super-admin
        {--email= : Email address for the account}
        {--name= : Display name (defaults to "Super Admin")}
        {--phone= : Contact number, optional}
        {--password= : Skip the prompt. Avoid — this lands in shell history and ps}
        {--force : Reset the password and reactivate the account if the email already exists}
        {--if-missing : Deploy mode: create the account configured in config/admin.php only if it does not exist, and skip quietly when nothing is configured}
        {--generate-password : Invent a strong password instead of asking for one, and print it once. Removes the need to configure a password at all}';

    protected $description = 'Create (or repair) the first Super Admin account';

    /** Full system authority is worth more than the 8 characters a self-service password gets. */
    private const int MIN_PASSWORD_LENGTH = 12;

    private const string ROLE = 'Super Admin';

    /** Set only when --generate-password invented one, so it can be printed once. */
    private ?string $generatedPassword = null;

    public function handle(): int
    {
        if ($this->option('if-missing')) {
            if ($this->option('force')) {
                $this->components->error(
                    '--if-missing and --force contradict each other: one refuses to touch an existing '
                    .'account, the other exists to overwrite one.'
                );

                return self::FAILURE;
            }

            if (! $this->bootstrapIsConfigured()) {
                $this->components->info(
                    'No SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD configured — skipping. Set both in this '
                    .'environment to have a deploy create the first Super Admin.'
                );

                return self::SUCCESS;
            }
        }

        $email = $this->resolveEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $existing = User::withTrashed()->where('email', $email)->first();

        // Before ensureRoleExists(), so a deploy that has nothing to do also
        // reseeds nothing: RbacSeeder syncs role permissions from
        // config/rbac.php, which is not a side effect this command should be
        // having on every release.
        if ($existing !== null && $this->option('if-missing')) {
            return $this->reportExistingAndLeaveItAlone($existing);
        }

        if (! $this->ensureRoleExists()) {
            return self::FAILURE;
        }

        if ($existing !== null && ! $this->option('force')) {
            return $this->repairWithoutTouchingCredentials($existing);
        }

        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        return $existing === null
            ? $this->createAccount($email, $password)
            : $this->resetAccount($existing, $password);
    }

    private function resolveEmail(): ?string
    {
        $email = trim((string) ($this->option('email') ?? ''));

        if ($email === '') {
            $email = $this->configuredString('admin.super_admin.email');
        }

        if ($email === '' && $this->input->isInteractive() && ! $this->option('if-missing')) {
            $email = trim((string) $this->ask('Email address for the Super Admin'));
        }

        if ($email === '') {
            $this->components->error('No email address given. Pass --email=you@example.com, or set SUPER_ADMIN_EMAIL.');

            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("Not a valid email address: {$email}");

            return null;
        }

        // The column is VARCHAR(190) with a unique index; a longer address
        // would be truncated into a collision rather than rejected.
        if (strlen($email) > 190) {
            $this->components->error('Email address is longer than the 190 characters the column holds.');

            return null;
        }

        return $email;
    }

    /**
     * Seeds the RBAC catalogue when the role is absent, because on a
     * migrate-only database it always is. RbacSeeder is idempotent
     * (findOrCreate plus syncPermissions), so running it here cannot
     * disturb an environment that already has its catalogue.
     */
    private function ensureRoleExists(): bool
    {
        if ($this->roleExists()) {
            return true;
        }

        $this->components->info('No '.self::ROLE.' role yet — seeding the RBAC catalogue from config/rbac.php.');

        try {
            $this->callSilent('db:seed', [
                '--class' => RbacSeeder::class,
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            $this->components->error('Could not seed the RBAC catalogue: '.$e->getMessage());

            return false;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! $this->roleExists()) {
            $this->components->error(
                'The '.self::ROLE.' role still does not exist after seeding. Check config/rbac.php and the '
                .config('rbac.guard').' guard.'
            );

            return false;
        }

        return true;
    }

    /**
     * @phpstan-impure Reads the database, so the answer changes once
     *                 RbacSeeder has run between two calls.
     */
    private function roleExists(): bool
    {
        return Role::where('name', self::ROLE)
            ->where('guard_name', config('rbac.guard'))
            ->exists();
    }

    /**
     * Both halves are required, and the check is deliberately about presence
     * rather than validity: an unset credential is an environment that has
     * opted out, while a malformed one is a mistake that should stop the
     * deploy. Only the first is a reason to skip.
     */
    private function bootstrapIsConfigured(): bool
    {
        $hasEmail = trim((string) ($this->option('email') ?? '')) !== ''
            || $this->configuredString('admin.super_admin.email') !== '';

        $hasPassword = (string) ($this->option('password') ?? '') !== ''
            || $this->configuredPassword() !== ''
            || (bool) $this->option('generate-password');

        return $hasEmail && $hasPassword;
    }

    private function configuredString(string $key): string
    {
        $value = config($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Not trimmed, unlike every other configured value here: leading and
     * trailing whitespace is part of a password, and silently eating it
     * would authenticate against something the operator never set.
     */
    private function configuredPassword(): string
    {
        $value = config('admin.super_admin.password');

        return is_string($value) ? $value : '';
    }

    /**
     * The --if-missing path for an account that is already there. Writes
     * nothing at all — this runs on every single deploy, so assigning the
     * role here would hand full system authority back to an account
     * somebody deliberately demoted, and would do it again every release.
     * It reports instead, and the repair stays a decision an operator makes
     * by running the command without --if-missing.
     */
    private function reportExistingAndLeaveItAlone(User $user): int
    {
        if ($user->trashed()) {
            $this->components->warn(
                "{$user->email} exists but is soft-deleted — left untouched. Restoring a deleted staff "
                .'account is not something a deploy should do on its own: run the command with --force.'
            );

            return self::SUCCESS;
        }

        $this->components->info("{$user->email} already exists — nothing to do.");

        if (! $user->hasRole(self::ROLE)) {
            $this->components->warn(
                'It does not hold the '.self::ROLE.' role, so it can sign in and do nothing. Run '
                ."`php artisan admin:create-super-admin --email={$user->email}` to assign it."
            );
        }

        if (! $user->isActive()) {
            $this->components->warn("Status is \"{$user->status}\", so login returns 403.");
        }

        return self::SUCCESS;
    }

    /**
     * The re-run path. An operator reaching for this command a second time
     * is almost always fixing a missing role, not asking for a credential
     * reset — so do the harmless half and say plainly what was left alone.
     */
    private function repairWithoutTouchingCredentials(User $user): int
    {
        if ($user->trashed()) {
            $this->components->error(
                "{$user->email} exists but is soft-deleted. Re-run with --force to restore and reset it."
            );

            return self::FAILURE;
        }

        $hadRole = $user->hasRole(self::ROLE);

        if (! $hadRole) {
            $before = $user->roles()->pluck('name')->all();
            $user->syncRoles([self::ROLE]);
            $this->audit($user, 'role_assigned', 'Super Admin role assigned from the console', [
                'before_roles' => $before,
                'after_roles' => [self::ROLE],
            ]);
            $this->components->info('Assigned the '.self::ROLE." role to {$user->email}.");
        } else {
            $this->components->info("{$user->email} already holds the ".self::ROLE.' role.');
        }

        $this->components->warn('Password left unchanged. Re-run with --force to reset it.');

        if (! $user->isActive()) {
            $this->components->warn(
                "Status is \"{$user->status}\", so login returns 403. Re-run with --force to reactivate."
            );
        }

        if ($user->locked_until?->isFuture()) {
            $this->components->warn(
                'Account is locked out until '.$user->locked_until->toDateTimeString().' after failed logins.'
            );
        }

        $this->printNextSteps($user);

        return self::SUCCESS;
    }

    private function resolvePassword(): ?string
    {
        $password = (string) ($this->option('password') ?? '');

        if ($password === '') {
            $password = $this->configuredPassword();
        }

        if ($password === '' && $this->option('generate-password')) {
            $password = Str::password(20);
            $this->generatedPassword = $password;
        }

        if ($password === '') {
            if (! $this->input->isInteractive() || $this->option('if-missing')) {
                $this->components->error(
                    'No password given and nothing to prompt on. Pass --password=..., or set SUPER_ADMIN_PASSWORD.'
                );

                return null;
            }

            $password = (string) $this->secret('Password');
            $confirmation = (string) $this->secret('Confirm password');

            if ($password !== $confirmation) {
                $this->components->error('The two passwords did not match.');

                return null;
            }
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(self::MIN_PASSWORD_LENGTH)]]
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('password'));

            return null;
        }

        return $password;
    }

    private function createAccount(string $email, string $password): int
    {
        $user = new User;
        $user->forceFill([
            'name' => $this->resolveName(),
            'email' => $email,
            'phone' => $this->resolvePhone(),
            'password' => $password,
            'status' => 'active',
        ])->save();

        $user->syncRoles([self::ROLE]);

        $this->audit($user, 'created', 'Super Admin account created from the console', [
            'email' => $user->email,
            'roles' => [self::ROLE],
        ]);

        $this->components->info("Created {$user->email} as ".self::ROLE.'.');
        $this->announceGeneratedPassword($user);
        $this->printNextSteps($user);

        return self::SUCCESS;
    }

    /**
     * --force is a credential reset, so it clears the login lockout and the
     * failed-attempt counter too: an operator who has just proven they hold
     * the server should not then be told to wait fifteen minutes.
     */
    private function resetAccount(User $user, string $password): int
    {
        $before = [
            'roles' => $user->roles()->pluck('name')->all(),
            'status' => $user->status,
            'trashed' => $user->trashed(),
        ];

        if ($user->trashed()) {
            $user->restore();
        }

        $attributes = [
            'password' => $password,
            'status' => 'active',
            'locked_until' => null,
            'failed_login_attempts' => 0,
        ];

        if ($this->option('name') !== null) {
            $attributes['name'] = $this->resolveName();
        }

        if ($this->option('phone') !== null) {
            $attributes['phone'] = $this->resolvePhone();
        }

        $user->forceFill($attributes)->save();
        $user->syncRoles([self::ROLE]);

        $this->audit($user, 'credentials_reset', 'Super Admin password reset from the console', [
            'before' => $before,
            'after' => ['roles' => [self::ROLE], 'status' => 'active'],
        ]);

        $this->components->info("Reset {$user->email} and confirmed the ".self::ROLE.' role.');
        $this->announceGeneratedPassword($user);
        $this->printNextSteps($user);

        return self::SUCCESS;
    }

    private function resolveName(): string
    {
        $name = trim((string) ($this->option('name') ?? ''));

        if ($name === '') {
            $name = $this->configuredString('admin.super_admin.name');
        }

        return $name === '' ? 'Super Admin' : Str::limit($name, 150, '');
    }

    private function resolvePhone(): ?string
    {
        $phone = trim((string) ($this->option('phone') ?? ''));

        if ($phone === '') {
            $phone = $this->configuredString('admin.super_admin.phone');
        }

        return $phone === '' ? null : Str::limit($phone, 20, '');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(User $user, string $event, string $description, array $properties): void
    {
        ActivityLog::create([
            'log_name' => 'user',
            'event' => $event,
            'description' => $description,
            // No causer: nobody was authenticated. That is the fact worth
            // recording — this account came from shell access to the host.
            'causer_type' => null,
            'causer_id' => null,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'properties' => $properties + ['source' => 'console:admin:create-super-admin'],
            'request_id' => substr((string) Str::ulid(), 0, 26),
            'severity' => 'warning',
        ]);
    }

    /**
     * Printed exactly once, at creation, because nothing can recover it
     * afterwards -- only a bcrypt hash is stored. Deliberately NOT masked:
     * a masked one-time password is no password at all. That does mean it
     * sits in whatever log this ran in, so the warning is part of the
     * output rather than something to remember from a README.
     */
    private function announceGeneratedPassword(User $user): void
    {
        if ($this->generatedPassword === null) {
            return;
        }

        $this->newLine();
        $this->line('  ****************************************************************');
        $this->line("  Generated password for {$user->email}:");
        $this->newLine();
        $this->line('      '.$this->generatedPassword);
        $this->newLine();
        $this->line('  Shown once. Only a bcrypt hash is kept, so nothing can print it');
        $this->line('  again. Anyone who can read this log can read it too -- sign in');
        $this->line('  and change it now.');
        $this->line('  ****************************************************************');
        $this->newLine();
    }

    private function printNextSteps(User $user): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('Email', (string) $user->email);
        $this->components->twoColumnDetail('Roles', $user->roles()->pluck('name')->implode(', ') ?: '—');
        $this->components->twoColumnDetail('Status', (string) $user->status);
        $this->newLine();

        if ($user->two_factor_confirmed_at === null) {
            $this->components->warn(
                'No 2FA on this account yet, so the first login needs no TOTP code. Set it up immediately '
                .'from the admin console — the local-only 2FA bypass in AuthController is inert outside '
                .'a dev box, and this account has every permission in the catalogue.'
            );
        }
    }
}
