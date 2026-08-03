<?php

namespace Tests\Feature\Admin;

use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VolunteerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $eventManager;

    private User $volunteerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('Super Admin');

        $this->eventManager = User::factory()->create(['status' => 'active']);
        $this->eventManager->assignRole('Event Manager');

        $this->volunteerUser = User::factory()->create(['status' => 'active']);
        $this->volunteerUser->assignRole('Volunteer');
    }

    public function test_volunteer_cannot_list_or_create_volunteers(): void
    {
        Sanctum::actingAs($this->volunteerUser, ['*'], 'web-admin');

        $this->getJson(route('api.v1.admin.volunteers.index'))->assertStatus(403);
        $this->postJson(route('api.v1.admin.volunteers.store'), [
            'name' => 'New Vol', 'email' => 'new@example.com', 'password' => 'password123', 'volunteer_code' => 'VOL-999',
        ])->assertStatus(403);
    }

    public function test_event_manager_can_create_list_update_and_assign_gate_to_a_volunteer(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $createResponse = $this->postJson(route('api.v1.admin.volunteers.store'), [
            'name' => 'Jane Volunteer',
            'email' => 'jane.volunteer@example.com',
            'password' => 'password123',
            'volunteer_code' => 'VOL-100',
            'team' => 'entry',
        ]);
        $createResponse->assertStatus(201)->assertJsonPath('data.volunteer_code', 'VOL-100');
        $volunteerUlid = $createResponse->json('data.ulid');

        $this->assertDatabaseHas('users', ['email' => 'jane.volunteer@example.com']);
        $volunteer = VolunteerProfile::where('ulid', $volunteerUlid)->firstOrFail();
        $this->assertTrue($volunteer->user->hasRole('Volunteer'));

        $this->getJson(route('api.v1.admin.volunteers.index'))
            ->assertStatus(200)->assertJsonFragment(['volunteer_code' => 'VOL-100']);

        $this->patchJson(route('api.v1.admin.volunteers.update', ['volunteer' => $volunteerUlid]), ['team' => 'vip'])
            ->assertStatus(200)->assertJsonPath('data.team', 'vip');

        $gate = Gate::factory()->create();

        $this->postJson(route('api.v1.admin.volunteers.assign-gate', ['volunteer' => $volunteerUlid]), [
            'gate_ulid' => $gate->ulid,
        ])->assertStatus(201)->assertJsonFragment(['code' => $gate->code]);

        $this->assertDatabaseHas('volunteer_gate_assignments', [
            'volunteer_profile_id' => $volunteer->id,
            'gate_id' => $gate->id,
        ]);

        $this->postJson(route('api.v1.admin.volunteers.revoke-access', ['volunteer' => $volunteerUlid]), [
            'reason' => 'No longer volunteering',
        ])->assertStatus(200)->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('volunteer_profiles', ['id' => $volunteer->id, 'is_active' => false]);
    }

    public function test_volunteer_code_must_be_unique(): void
    {
        VolunteerProfile::factory()->create(['volunteer_code' => 'VOL-DUP']);

        Sanctum::actingAs($this->superAdmin, ['*'], 'web-admin');

        $this->postJson(route('api.v1.admin.volunteers.store'), [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'password123',
            'volunteer_code' => 'VOL-DUP',
        ])->assertStatus(422);
    }
}
