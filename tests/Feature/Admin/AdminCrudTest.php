<?php

namespace Tests\Feature\Admin;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
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
