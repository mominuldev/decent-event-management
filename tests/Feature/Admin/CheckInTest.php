<?php

namespace Tests\Feature\Admin;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerGateAssignment;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private User $eventManager;

    private User $volunteer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->eventManager = User::factory()->create(['status' => 'active']);
        $this->eventManager->assignRole('Event Manager');

        $this->volunteer = User::factory()->create(['status' => 'active']);
        $this->volunteer->assignRole('Volunteer');
    }

    public function test_manual_override_admits_the_ticket_and_logs_the_reason(): void
    {
        $gate = Gate::factory()->create();
        $ticket = Ticket::factory()->create(['admits_total' => 2, 'admitted_count' => 0, 'status' => 'active']);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $response = $this->postJson(route('api.v1.admin.check-ins.manual-override'), [
            'ticket_ulid' => $ticket->ulid,
            'gate_ulid' => $gate->ulid,
            'party_size' => 1,
            'reason' => 'Cracked phone screen, QR unreadable',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.result', 'manual_override');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'admitted_count' => 1,
        ]);

        $this->assertDatabaseHas('check_ins', [
            'ticket_id' => $ticket->id,
            'gate_id' => $gate->id,
            'is_manual_override' => true,
            'override_reason' => 'Cracked phone screen, QR unreadable',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'log_name' => 'checkin',
            'event' => 'manual_override',
        ]);
    }

    public function test_manual_override_requires_a_reason(): void
    {
        $gate = Gate::factory()->create();
        $ticket = Ticket::factory()->create();

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $this->postJson(route('api.v1.admin.check-ins.manual-override'), [
            'ticket_ulid' => $ticket->ulid,
            'gate_ulid' => $gate->ulid,
            'party_size' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_manual_override_is_denied_for_volunteers(): void
    {
        $gate = Gate::factory()->create();
        $ticket = Ticket::factory()->create();

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');

        $this->postJson(route('api.v1.admin.check-ins.manual-override'), [
            'ticket_ulid' => $ticket->ulid,
            'gate_ulid' => $gate->ulid,
            'party_size' => 1,
            'reason' => 'Attempted override',
        ])->assertStatus(403);
    }

    public function test_resolve_conflict_422s_when_there_is_nothing_to_resolve(): void
    {
        $checkIn = CheckIn::factory()->create(['conflict_flag' => false]);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $this->postJson(route('api.v1.admin.check-ins.resolve-conflict', ['check_in' => $checkIn->ulid]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_conflict_to_resolve');
    }

    public function test_resolve_conflict_succeeds_on_a_flagged_check_in(): void
    {
        $checkIn = CheckIn::factory()->create(['conflict_flag' => true, 'conflict_resolved_at' => null]);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');

        $this->postJson(route('api.v1.admin.check-ins.resolve-conflict', ['check_in' => $checkIn->ulid]), [
            'note' => 'Confirmed with the other gate volunteer, single valid admission.',
        ])->assertStatus(200)->assertJsonPath('data.conflict_resolved_by', $this->eventManager->name);

        $this->assertDatabaseHas('check_ins', [
            'id' => $checkIn->id,
            'conflict_resolved_by_user_id' => $this->eventManager->id,
        ]);
    }

    public function test_live_dashboard_scopes_volunteer_to_their_assigned_gate(): void
    {
        $assignedGate = Gate::factory()->create(['code' => 'GATE-ASSIGNED']);
        $otherGate = Gate::factory()->create(['code' => 'GATE-OTHER']);

        $volunteerProfile = VolunteerProfile::factory()->create(['user_id' => $this->volunteer->id]);
        VolunteerGateAssignment::factory()->create([
            'volunteer_profile_id' => $volunteerProfile->id,
            'gate_id' => $assignedGate->id,
        ]);

        Sanctum::actingAs($this->volunteer, ['*'], 'web-admin');
        $volunteerResponse = $this->getJson(route('api.v1.admin.check-ins.live-dashboard'));

        $volunteerResponse->assertStatus(200)
            ->assertJsonFragment(['code' => 'GATE-ASSIGNED'])
            ->assertJsonMissing(['code' => 'GATE-OTHER']);

        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
        $eventManagerResponse = $this->getJson(route('api.v1.admin.check-ins.live-dashboard'));

        $eventManagerResponse->assertStatus(200)
            ->assertJsonFragment(['code' => 'GATE-ASSIGNED'])
            ->assertJsonFragment(['code' => 'GATE-OTHER']);
    }
}
