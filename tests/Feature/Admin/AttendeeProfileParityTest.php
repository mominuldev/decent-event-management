<?php

namespace Tests\Feature\Admin;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin attendee dialog can correct everything the attendee can edit on
 * their own profile page. Before this, eight of those fields were read-only
 * in the console and not accepted by the admin request at all — so a wrong
 * shirt size or emergency number could only be fixed by the attendee
 * signing in, which for most of the roster means a support call.
 */
class AttendeeProfileParityTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        $this->seed(RbacSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('Super Admin');

        Sanctum::actingAs($admin, ['admin'], 'web-admin');
    }

    public function test_an_admin_may_edit_every_field_the_profile_page_offers(): void
    {
        $attendee = Attendee::factory()->create([
            'whatsapp_number' => null,
            'designation' => null,
            'organization' => null,
            'tshirt_required' => false,
            'tshirt_size' => null,
            'country' => 'BD',
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
        ]);

        $this->actingAsAdmin();

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'whatsapp_number' => '+880 1711-000999',
            'designation' => 'Head Teacher',
            'organization' => 'Nawabganj High School',
            'tshirt_required' => true,
            'tshirt_size' => 'XL',
            'country' => 'gb',
            'emergency_contact_name' => 'Rahima Begum',
            'emergency_contact_phone' => '01811000111',
        ])
            ->assertStatus(200)
            // Normalised the same way the profile page's own request does.
            ->assertJsonPath('data.whatsapp_number', '+8801711000999')
            ->assertJsonPath('data.designation', 'Head Teacher')
            ->assertJsonPath('data.organization', 'Nawabganj High School')
            ->assertJsonPath('data.tshirt_required', true)
            ->assertJsonPath('data.tshirt_size', 'XL')
            // Uppercased: the column is CHAR(2) holding an ISO code.
            ->assertJsonPath('data.country', 'GB')
            ->assertJsonPath('data.emergency_contact_name', 'Rahima Begum')
            ->assertJsonPath('data.emergency_contact_phone', '01811000111');
    }

    public function test_a_requested_tshirt_needs_a_size(): void
    {
        $attendee = Attendee::factory()->create(['tshirt_required' => false, 'tshirt_size' => null]);

        $this->actingAsAdmin();

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'tshirt_required' => true,
            'tshirt_size' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tshirt_size']);

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'tshirt_required' => true,
            'tshirt_size' => 'Large',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tshirt_size']);
    }

    /**
     * `attendees.country` is CHAR(2) NOT NULL. The self-service request
     * still validates it at `max:100`, so "Bangladesh" reaches MySQL there
     * and dies; the admin path must answer a 422 instead.
     */
    public function test_a_country_name_is_a_validation_error_not_a_database_error(): void
    {
        $attendee = Attendee::factory()->create(['country' => 'BD']);

        $this->actingAsAdmin();

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'country' => 'Bangladesh',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['country']);

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'country' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['country']);

        $this->assertSame('BD', $attendee->refresh()->country);
    }
}
