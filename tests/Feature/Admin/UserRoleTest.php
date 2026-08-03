<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $eventManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('Super Admin');

        $this->eventManager = User::factory()->create(['status' => 'active']);
        $this->eventManager->assignRole('Event Manager');
    }

    public function test_event_manager_cannot_list_users_or_roles_or_assign_roles(): void
    {
        $target = User::factory()->create(['status' => 'active']);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $this->getJson(route('api.v1.admin.users.index'))->assertStatus(403);
        $this->getJson(route('api.v1.admin.roles.index'))->assertStatus(403);
        $this->postJson(route('api.v1.admin.users.assign-role', ['user' => $target->ulid]), ['role' => 'Volunteer'])
            ->assertStatus(403);
    }

    public function test_super_admin_can_list_users_list_roles_and_assign_a_role(): void
    {
        $target = User::factory()->create(['status' => 'active']);
        $target->assignRole('Volunteer');

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');

        $this->getJson(route('api.v1.admin.users.index'))
            ->assertStatus(200)->assertJsonFragment(['email' => $target->email]);

        $this->getJson(route('api.v1.admin.users.show', ['user' => $target->ulid]))
            ->assertStatus(200)->assertJsonPath('data.roles.0', 'Volunteer');

        $this->getJson(route('api.v1.admin.roles.index'))
            ->assertStatus(200)->assertJsonFragment(['name' => 'Event Manager']);

        $this->postJson(route('api.v1.admin.users.assign-role', ['user' => $target->ulid]), ['role' => 'Event Manager'])
            ->assertStatus(200)->assertJsonPath('data.roles.0', 'Event Manager');

        $this->assertTrue($target->fresh()->hasRole('Event Manager'));
        $this->assertFalse($target->fresh()->hasRole('Volunteer'));
    }

    public function test_assigning_a_nonexistent_role_fails_validation(): void
    {
        $target = User::factory()->create(['status' => 'active']);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');

        $this->postJson(route('api.v1.admin.users.assign-role', ['user' => $target->ulid]), ['role' => 'Nonexistent Role'])
            ->assertStatus(422);
    }
}
