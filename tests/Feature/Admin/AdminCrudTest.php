<?php

namespace Tests\Feature\Admin;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'status' => 'active',
        ]);
        $this->admin->assignRole('Super Admin');
    }

    public function test_admin_can_list_and_update_attendees(): void
    {
        $attendee = Attendee::factory()->create(['full_name' => 'John Doe']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $response = $this->getJson(route('api.v1.admin.attendees.index'));
        $response->assertStatus(200)
            ->assertJsonFragment(['full_name' => 'John Doe']);

        $updateResponse = $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $attendee->ulid]), [
            'full_name' => 'John Smith',
            'participant_type' => 'former_student',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.full_name', 'John Smith');

        $this->assertDatabaseHas('attendees', [
            'id' => $attendee->id,
            'full_name' => 'John Smith',
        ]);
    }

    public function test_admin_can_manage_ticket_types(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $createResponse = $this->postJson(route('api.v1.admin.ticket-types.store'), [
            'code' => 'VIP',
            'name' => 'VIP Admission',
            'base_price_paisa' => 500000,
            'additional_adult_price_paisa' => 200000,
            'additional_child_price_paisa' => 100000,
            'currency' => 'BDT',
            'base_admits' => 1,
            'max_admits' => 5,
            'quantity_total' => 100,
            'allowed_participant_types' => ['former_student', 'current_student'],
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.code', 'VIP');

        $this->assertDatabaseHas('ticket_types', [
            'code' => 'VIP',
        ]);
    }

    public function test_admin_can_set_and_clear_the_current_student_price(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $createResponse = $this->postJson(route('api.v1.admin.ticket-types.store'), [
            'code' => 'CENX',
            'name' => 'Centennial',
            'base_price_paisa' => 250000,
            'additional_adult_price_paisa' => 200000,
            'additional_child_price_paisa' => 200000,
            'current_student_price_paisa' => 50000,
            'base_admits' => 1,
            'max_admits' => 9,
            'quantity_total' => 100,
            'allowed_participant_types' => ['former_student', 'current_student'],
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.current_student_price_paisa', 50000);

        $ulid = $createResponse->json('data.ulid');

        // Clearing it back to null is how an admin withdraws the tier —
        // it must be reachable, not just settable.
        $this->patchJson(route('api.v1.admin.ticket-types.update', ['ticket_type' => $ulid]), [
            'current_student_price_paisa' => null,
        ])->assertStatus(200)
            ->assertJsonPath('data.current_student_price_paisa', null);

        $this->assertDatabaseHas('ticket_types', [
            'code' => 'CENX',
            'current_student_price_paisa' => null,
        ]);
    }

    /**
     * The student rate is a price, so it is under the same post-sale lock
     * as the other three. Leaving it out of `$restrictedKeys` would have
     * left one editable money column on a tier that has already sold.
     */
    public function test_the_current_student_price_is_locked_once_the_tier_has_sold(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $ticketType = TicketType::factory()->create([
            'base_price_paisa' => 250000,
            'current_student_price_paisa' => 50000,
            'quantity_sold' => 3,
        ]);

        $this->patchJson(route('api.v1.admin.ticket-types.update', ['ticket_type' => $ticketType->ulid]), [
            'current_student_price_paisa' => 10000,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'update_prevented');

        $this->assertSame(50000, (int) $ticketType->fresh()->current_student_price_paisa);
    }

    public function test_admin_can_update_event_settings(): void
    {
        $setting = EventSetting::factory()->create([
            'key' => 'site_title',
            'value' => 'Old Title',
        ]);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $response = $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'site_title']), [
            'value' => 'New Title',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.value', 'New Title');

        $this->assertDatabaseHas('event_settings', [
            'key' => 'site_title',
            'value' => 'New Title',
        ]);
    }
}
