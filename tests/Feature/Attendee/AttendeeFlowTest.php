<?php

namespace Tests\Feature\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendeeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendee_can_get_and_update_profile(): void
    {
        $attendee = Attendee::factory()->create([
            'full_name' => 'Original Name',
        ]);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $response = $this->getJson(route('api.v1.attendee.me.show'));
        $response->assertStatus(200)
            ->assertJsonPath('data.full_name', 'Original Name');

        $updateResponse = $this->patchJson(route('api.v1.attendee.me.update'), [
            'full_name' => 'Updated Name',
            'occupation' => 'Engineer',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.full_name', 'Updated Name');

        $this->assertDatabaseHas('attendees', [
            'id' => $attendee->id,
            'full_name' => 'Updated Name',
        ]);
    }

    public function test_attendee_can_list_and_cancel_registration(): void
    {
        $attendee = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create(['quantity_reserved' => 1]);
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'pending_payment',
        ]);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $response = $this->getJson(route('api.v1.attendee.registrations.index'));
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $cancelResponse = $this->postJson(route('api.v1.attendee.registrations.cancel', ['registration' => $registration->ulid]));
        $cancelResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('ticket_types', [
            'id' => $ticketType->id,
            'quantity_reserved' => 0,
        ]);
    }
}
