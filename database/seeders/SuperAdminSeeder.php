<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Creates the first Super Admin, and nothing else.
 *
 * Split out of DatabaseSeeder so it can be run on its own:
 *
 *   php artisan db:seed --class=SuperAdminSeeder
 *
 * DatabaseSeeder still calls it, so a local `db:seed` behaves exactly as
 * it did — but running the whole thing also drags in DummyDataSeeder's
 * fake registrations, payments and tickets, which is not what somebody
 * who just needs an account to log in with is asking for.
 *
 * On a live environment prefer `php artisan admin:create-super-admin`:
 * it writes the activity_logs row, refuses to overwrite an existing
 * password without --force, and can invent a strong one. This seeder is
 * the convenience path, not the audited one.
 *
 * The account it creates comes from config/admin.php (i.e. the host's own
 * .env), the same source the command reads, so the two cannot disagree
 * about which address is the bootstrap admin.
 */
class SuperAdminSeeder extends Seeder
{
    private const string ROLE = 'Super Admin';

    /** Full system authority is worth more than the 8 characters a self-service password gets. */
    private const int MIN_PASSWORD_LENGTH = 12;

    public function run(): void
    {
        $email = (string) config('admin.super_admin.email');

        if ($email === '') {
            throw new RuntimeException('No SUPER_ADMIN_EMAIL configured — nothing to create an account at.');
        }

        // syncRoles() throws RoleDoesNotExist against a database that has
        // only ever had `migrate --force` run against it, which is every
        // environment the deploy workflow produces. RbacSeeder is
        // idempotent, so calling it here cannot disturb an existing
        // catalogue.
        if (! Role::where('name', self::ROLE)->where('guard_name', config('rbac.guard'))->exists()) {
            $this->call(RbacSeeder::class);
        }

        $superAdmin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => (string) (config('admin.super_admin.name') ?: 'Super Admin'),
                'phone' => config('admin.super_admin.phone'),
                'password' => $this->password(),
                'status' => 'active',
            ]
        );

        // Deliberately outside firstOrCreate: an existing account keeps its
        // password, name and status, and only ever gains the role back. This
        // is the repair path for an account that exists but cannot do
        // anything.
        $superAdmin->syncRoles([self::ROLE]);

        $this->command->info(
            $superAdmin->wasRecentlyCreated
                ? "Created Super Admin {$email}."
                : "Super Admin {$email} already exists — role re-assigned, password left alone."
        );
    }

    /**
     * `password` is fine on a laptop and is exactly what must never reach a
     * live database, so outside local/testing a real one has to be
     * configured rather than defaulted into existence.
     */
    private function password(): string
    {
        $configured = (string) (config('admin.super_admin.password') ?? '');

        if ($configured !== '') {
            if (strlen($configured) < self::MIN_PASSWORD_LENGTH) {
                throw new RuntimeException(
                    'SUPER_ADMIN_PASSWORD is shorter than '.self::MIN_PASSWORD_LENGTH.' characters.'
                );
            }

            return $configured;
        }

        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'Refusing to seed the default password outside local. Set SUPER_ADMIN_PASSWORD, or run '
                .'`php artisan admin:create-super-admin --generate-password`.'
            );
        }

        return 'password';
    }
}
