<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds roles and permissions from the versioned catalogue in
 * config/rbac.php — docs/02 §2.5. Never create roles/permissions ad hoc
 * outside this seeder, or staging and production will drift.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('rbac.guard');

        foreach (config('rbac.permissions') as $name) {
            Permission::findOrCreate($name, $guard);
        }

        foreach (config('rbac.roles') as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, $guard);

            if ($permissions === ['*']) {
                $role->syncPermissions(Permission::where('guard_name', $guard)->get());
            } else {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forget('spatie.permission.cache');
    }
}
