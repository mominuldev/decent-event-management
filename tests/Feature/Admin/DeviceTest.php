<?php

namespace Tests\Feature\Admin;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $volunteerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('Super Admin');

        $this->volunteerUser = User::factory()->create(['status' => 'active']);
        $this->volunteerUser->assignRole('Volunteer');
    }

    public function test_volunteer_can_view_sync_status_but_not_list_or_revoke_devices(): void
    {
        $volunteerProfile = VolunteerProfile::factory()->create(['user_id' => $this->volunteerUser->id]);
        $device = CheckInDevice::factory()->create([
            'assigned_volunteer_profile_id' => $volunteerProfile->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->volunteerUser, ['*'], 'web-admin');

        $this->getJson(route('api.v1.admin.devices.sync-status', ['device' => $device->ulid]))
            ->assertStatus(200)->assertJsonPath('status', 'active');

        $this->getJson(route('api.v1.admin.devices.index'))->assertStatus(403);
        $this->postJson(route('api.v1.admin.devices.revoke', ['device' => $device->ulid]))->assertStatus(403);
    }

    public function test_super_admin_can_list_view_and_revoke_a_device(): void
    {
        $device = CheckInDevice::factory()->create(['status' => 'active', 'sanctum_token_id' => null]);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');

        $this->getJson(route('api.v1.admin.devices.index'))
            ->assertStatus(200)->assertJsonFragment(['device_code' => $device->device_code]);

        $this->getJson(route('api.v1.admin.devices.show', ['device' => $device->ulid]))
            ->assertStatus(200)->assertJsonPath('data.ulid', $device->ulid);

        $this->postJson(route('api.v1.admin.devices.revoke', ['device' => $device->ulid]))
            ->assertStatus(200)->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseHas('check_in_devices', ['id' => $device->id, 'status' => 'revoked']);
    }
}
