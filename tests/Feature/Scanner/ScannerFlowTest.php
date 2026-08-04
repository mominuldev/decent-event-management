<?php

namespace Tests\Feature\Scanner;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\EventSession;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerGateAssignment;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\QrCode;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Services\QrSigner;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScannerFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $volunteerUser;

    private VolunteerProfile $volunteerProfile;

    private CheckInDevice $device;

    private Gate $gate;

    private EventSession $session;

    private string $plainTextToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->session = EventSession::factory()->create([
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(5),
            'is_active' => true,
        ]);

        $this->gate = Gate::factory()->create([
            'event_session_id' => $this->session->id,
            'is_active' => true,
        ]);

        $this->volunteerUser = User::factory()->create(['status' => 'active']);
        $this->volunteerUser->assignRole('Volunteer');

        $this->volunteerProfile = VolunteerProfile::factory()->create([
            'user_id' => $this->volunteerUser->id,
            'is_active' => true,
        ]);

        VolunteerGateAssignment::create([
            'volunteer_profile_id' => $this->volunteerProfile->id,
            'gate_id' => $this->gate->id,
            'event_session_id' => $this->session->id,
        ]);

        $token = $this->volunteerUser->createToken('scanner-device', ['scanner']);
        $this->plainTextToken = $token->plainTextToken;

        $this->device = CheckInDevice::factory()->create([
            'assigned_volunteer_profile_id' => $this->volunteerProfile->id,
            'sanctum_token_id' => $token->accessToken->id,
            'status' => 'active',
        ]);
    }

    public function test_scanner_can_fetch_manifest(): void
    {
        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->create(['attendee_id' => $attendee->id]);
        $ticket = Ticket::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'event_session_id' => $this->session->id,
            'status' => 'active',
        ]);

        $response = $this->withToken($this->plainTextToken)
            ->withHeaders(['X-Gate-Id' => $this->gate->ulid])
            ->getJson(route('scanner.v1.manifest.show'));

        $response->assertStatus(200)
            ->assertHeader('ETag')
            ->assertJsonFragment(['ticket_number' => $ticket->ticket_number]);
    }

    public function test_scanner_can_submit_scan_batch(): void
    {
        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->create(['attendee_id' => $attendee->id]);
        $ticket = Ticket::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'event_session_id' => $this->session->id,
            'status' => 'active',
            'admits_total' => 1,
            'admitted_count' => 0,
        ]);

        $signed = app(QrSigner::class)->sign($ticket->ulid, 1, now()->addYear()->timestamp);
        $qrCode = QrCode::factory()->create([
            'ticket_id' => $ticket->id,
            'payload' => $signed['payload'],
            'payload_hash' => $signed['payload_hash'],
            'signature' => $signed['signature'],
            'signing_key_id' => $signed['signing_key_id'],
            'is_active' => true,
        ]);

        $scanUuid = (string) Str::uuid();

        $payload = [
            'gate_id' => (string) $this->gate->id,
            'scans' => [
                [
                    'client_scan_uuid' => $scanUuid,
                    'raw_payload' => $qrCode->payload,
                    'party_size' => 1,
                    'scanned_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $response = $this->withToken($this->plainTextToken)
            ->withHeaders(['X-Gate-Id' => $this->gate->ulid])
            ->postJson(route('scanner.v1.scans.store'), $payload);

        $response->assertStatus(200)
            ->assertJsonPath('results.0.result', 'admitted');

        $this->assertDatabaseHas('check_ins', [
            'client_scan_uuid' => $scanUuid,
            'result' => 'admitted',
        ]);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'admitted_count' => 1,
            'status' => 'fully_admitted',
        ]);
    }

    public function test_manifest_publishes_the_active_signing_key_for_offline_verification(): void
    {
        $response = $this->withToken($this->plainTextToken)
            ->withHeaders(['X-Gate-Id' => $this->gate->ulid])
            ->getJson(route('scanner.v1.manifest.show'));

        $response->assertStatus(200);
        $activeKeyId = app(QrSigner::class)->activeKeyId();
        $response->assertJsonPath('meta.active_key_id', $activeKeyId);
        $response->assertJsonStructure(['meta' => ['keys']]);
        $this->assertArrayHasKey($activeKeyId, $response->json('meta.keys'));
    }

    public function test_manifest_delta_since_returns_only_tickets_changed_after_that_version_including_revoked(): void
    {
        $ticketType = TicketType::factory()->create();

        $stale = Ticket::factory()->create(['ticket_type_id' => $ticketType->id, 'status' => 'active']);
        $baseline = (int) Ticket::max('manifest_version');

        $changed = Ticket::factory()->create([
            'ticket_type_id' => $ticketType->id,
            'status' => 'active',
            'manifest_version' => $baseline + 1,
        ]);
        $revoked = Ticket::factory()->create([
            'ticket_type_id' => $ticketType->id,
            'status' => 'voided',
            'manifest_version' => $baseline + 2,
        ]);

        $response = $this->withToken($this->plainTextToken)
            ->withHeaders(['X-Gate-Id' => $this->gate->ulid])
            ->getJson(route('scanner.v1.manifest.show', ['since' => $baseline]));

        $response->assertStatus(200);
        $ulids = collect($response->json('data'))->pluck('ticket_ulid');

        $this->assertContains($changed->ulid, $ulids);
        $this->assertContains($revoked->ulid, $ulids);
        $this->assertNotContains($stale->ulid, $ulids);
    }

    public function test_manifest_cold_start_omits_voided_tickets_but_delta_would_include_them(): void
    {
        $voided = Ticket::factory()->create(['status' => 'voided']);

        $response = $this->withToken($this->plainTextToken)
            ->withHeaders(['X-Gate-Id' => $this->gate->ulid])
            ->getJson(route('scanner.v1.manifest.show'));

        $response->assertStatus(200);
        $this->assertNotContains($voided->ulid, collect($response->json('data'))->pluck('ticket_ulid'));
    }

    public function test_manifest_fetch_updates_the_device_manifest_version(): void
    {
        Ticket::factory()->create(['status' => 'active']);

        $this->withToken($this->plainTextToken)
            ->withHeaders(['X-Gate-Id' => $this->gate->ulid])
            ->getJson(route('scanner.v1.manifest.show'))
            ->assertStatus(200);

        $this->device->refresh();
        $this->assertSame((int) Ticket::max('manifest_version'), $this->device->manifest_version);
        $this->assertNotNull($this->device->last_sync_at);
    }
}
