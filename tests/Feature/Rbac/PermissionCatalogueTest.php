<?php

namespace Tests\Feature\Rbac;

use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The roles/permissions catalogue is seeded from config/rbac.php, which
 * is the versioned source of truth (docs/02 §2.5) — these tests guard
 * against silent drift in that config.
 */
class PermissionCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_every_permission_in_the_catalogue_is_seeded(): void
    {
        foreach (config('rbac.permissions') as $name) {
            $this->assertTrue(
                Permission::where('name', $name)->where('guard_name', 'web-admin')->exists(),
                "Permission [{$name}] was not seeded."
            );
        }
    }

    public function test_super_admin_has_every_permission(): void
    {
        $role = Role::findByName('Super Admin', 'web-admin');

        $this->assertSame(
            Permission::where('guard_name', 'web-admin')->count(),
            $role->permissions()->count()
        );
    }

    public function test_volunteer_cannot_perform_manual_override(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Volunteer');

        $this->assertFalse($user->can('checkin.manual_override'));
        $this->assertTrue($user->can('checkin.scan'));
    }

    public function test_only_super_admin_can_manage_gateway_credentials(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $eventManager = User::factory()->create();
        $eventManager->assignRole('Event Manager');

        $this->assertTrue($superAdmin->can('payment.manage_gateway_credentials'));
        $this->assertFalse($eventManager->can('payment.manage_gateway_credentials'));
    }

    public function test_event_manager_can_refund_but_not_rotate_signing_keys(): void
    {
        $eventManager = User::factory()->create();
        $eventManager->assignRole('Event Manager');

        $this->assertTrue($eventManager->can('payment.refund'));
        $this->assertFalse($eventManager->can('qr.rotate_signing_key'));
    }
}
