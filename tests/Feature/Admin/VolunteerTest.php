<?php

namespace Tests\Feature\Admin;

use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_an_event_manager_can_edit_the_volunteers_account_details(): void
    {
        $volunteer = VolunteerProfile::factory()->create();
        $user = User::findOrFail($volunteer->user_id);
        $originalHash = $user->password;

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $this->patchJson(route('api.v1.admin.volunteers.update', ['volunteer' => $volunteer->ulid]), [
            'name' => 'Renamed Volunteer',
            'email' => 'renamed@example.com',
            'phone' => '+8801711000000',
            'team' => 'vip',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.user.name', 'Renamed Volunteer')
            ->assertJsonPath('data.user.email', 'renamed@example.com')
            ->assertJsonPath('data.user.phone', '+8801711000000')
            ->assertJsonPath('data.team', 'vip');

        $this->assertDatabaseHas('users', [
            'id' => $volunteer->user_id,
            'name' => 'Renamed Volunteer',
            'email' => 'renamed@example.com',
        ]);
        // No password was sent, so the credential is untouched.
        $this->assertSame($originalHash, User::findOrFail($user->id)->password);
    }

    public function test_a_password_is_reset_only_when_one_is_sent(): void
    {
        $volunteer = VolunteerProfile::factory()->create();
        $user = User::findOrFail($volunteer->user_id);
        $originalHash = $user->password;

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $url = route('api.v1.admin.volunteers.update', ['volunteer' => $volunteer->ulid]);

        // A blank password from a form field left empty means "leave it alone".
        $this->patchJson($url, ['password' => null, 'team' => 'entry'])->assertStatus(200);
        $this->assertSame($originalHash, User::findOrFail($user->id)->password);

        $this->patchJson($url, ['password' => 'short'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertSame($originalHash, User::findOrFail($user->id)->password);

        $this->patchJson($url, ['password' => 'a-new-password-123'])->assertStatus(200);
        $fresh = User::findOrFail($user->id);
        $this->assertNotSame($originalHash, $fresh->password);
        $this->assertTrue(Hash::check('a-new-password-123', $fresh->password));
    }

    public function test_the_email_must_not_belong_to_another_account_but_may_stay_the_volunteers_own(): void
    {
        $volunteer = VolunteerProfile::factory()->create();
        $user = User::findOrFail($volunteer->user_id);
        $other = User::factory()->create(['email' => 'taken@example.com']);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $url = route('api.v1.admin.volunteers.update', ['volunteer' => $volunteer->ulid]);

        $this->patchJson($url, ['email' => $other->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Re-saving the address already on the record is not a conflict with itself.
        $this->patchJson($url, ['email' => $user->email, 'team' => 'gate-b'])
            ->assertStatus(200)
            ->assertJsonPath('data.team', 'gate-b');
    }

    public function test_reactivating_a_revoked_volunteer_clears_the_revocation(): void
    {
        $volunteer = VolunteerProfile::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $this->postJson(route('api.v1.admin.volunteers.revoke-access', ['volunteer' => $volunteer->ulid]), [
            'reason' => 'Left early',
        ])->assertStatus(200);
        $this->assertTrue(VolunteerProfile::findOrFail($volunteer->id)->isRevoked());

        $this->patchJson(route('api.v1.admin.volunteers.update', ['volunteer' => $volunteer->ulid]), ['is_active' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.revoked_at', null);

        $fresh = VolunteerProfile::findOrFail($volunteer->id);
        $this->assertFalse($fresh->isRevoked());
        $this->assertNull($fresh->revoked_by_user_id);
    }

    public function test_an_over_length_name_is_a_validation_error_not_a_database_error(): void
    {
        $volunteer = VolunteerProfile::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        // users.name is VARCHAR(150); the rule used to allow 190.
        $this->patchJson(route('api.v1.admin.volunteers.update', ['volunteer' => $volunteer->ulid]), [
            'name' => str_repeat('a', 151),
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->postJson(route('api.v1.admin.volunteers.store'), [
            'name' => str_repeat('a', 151),
            'email' => 'long@example.com',
            'password' => 'password123',
            'volunteer_code' => 'VOL-LONG',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }
}
