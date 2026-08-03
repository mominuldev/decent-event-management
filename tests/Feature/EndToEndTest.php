<?php

namespace Tests\Feature;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\EventSession;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerGateAssignment;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\QrCode;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end test covering the complete user journey:
 * Registration → Payment → Ticketing → Admission
 *
 * This test validates the entire system flow works correctly,
 * satisfying Phase 2 exit criteria.
 */
class EndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_registration_to_admission_flow(): void
    {
        // Setup: Create ticket type and event session
        $this->seed(RbacSeeder::class);

        $ticketType = TicketType::factory()->create([
            'name' => 'General Alumni',
            'base_price_paisa' => 100000,
            'quantity_total' => 100,
            'quantity_reserved' => 0,
            'quantity_sold' => 0,
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addDays(10),
        ]);

        $session = EventSession::factory()->create([
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(5),
            'is_active' => true,
        ]);

        $gate = Gate::factory()->create([
            'event_session_id' => $session->id,
            'is_active' => true,
        ]);

        // STEP 1: Public creates registration
        $registrationPayload = [
            'full_name' => 'Rahim Uddin',
            'mobile' => '+8801712345678',
            'email' => 'rahim@example.com',
            'gender' => 'male',
            'participant_type' => 'former_student',
            'ssc_batch_year' => 2010,
            'ticket_type_ulid' => $ticketType->ulid,
            'participation_type' => 'single',
            'adults_count' => 1,
            'children_count' => 0,
            'idempotency_key' => 'test-e2e-flow-12345',
        ];

        $registrationResponse = $this->postJson(route('api.v1.public.registrations.store'), $registrationPayload);

        $registrationResponse->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_payment');

        $attendee = Attendee::where('mobile', '+8801712345678')->first();
        $registration = Registration::where('attendee_id', $attendee->id)->first();

        $this->assertNotNull($attendee);
        $this->assertNotNull($registration);
        $this->assertEquals('pending_payment', $registration->status);

        // STEP 2: Create payment (simulating gateway return)
        $payment = Payment::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'amount_due_paisa' => $ticketType->base_price_paisa,
            'amount_paid_paisa' => $ticketType->base_price_paisa,
            'status' => 'awaiting_verification',
            'manual_trx_id' => 'TRX-E2E-TEST-001',
            'currency' => 'BDT',
        ]);

        $this->assertDatabaseHas('payments', [
            'registration_id' => $registration->id,
            'status' => 'awaiting_verification',
        ]);

        // STEP 3: Admin verifies payment
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('Event Manager');

        Sanctum::actingAs($admin, ['*'], 'web-admin');

        $verificationResponse = $this->postJson(route('api.v1.admin.payments.verify-manual', [
            'payment' => $payment->ulid,
        ]), [
            'verification_note' => 'Payment confirmed via bank statement',
        ]);

        $verificationResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'succeeded');

        // Verify state transitions
        $registration->refresh();
        $payment->refresh();

        $this->assertEquals('confirmed', $registration->status);

        // STEP 4: Ticket is automatically issued
        $ticket = Ticket::where('registration_id', $registration->id)->first();

        $this->assertNotNull($ticket);
        $this->assertEquals('active', $ticket->status);
        $this->assertEquals($attendee->id, $ticket->attendee_id);
        $this->assertEquals($ticketType->id, $ticket->ticket_type_id);

        // Verify capacity was updated
        $ticketType->refresh();
        $this->assertEquals(1, $ticketType->quantity_sold);

        // STEP 5: QR code is generated
        $qrCode = QrCode::where('ticket_id', $ticket->id)->first();

        $this->assertNotNull($qrCode);
        $this->assertEquals($ticket->id, $qrCode->ticket_id);
        $this->assertNotEmpty($qrCode->payload);

        // STEP 6: Scanner admits attendee
        $volunteerUser = User::factory()->create(['status' => 'active']);
        $volunteerUser->assignRole('Volunteer');

        $volunteerProfile = VolunteerProfile::factory()->create([
            'user_id' => $volunteerUser->id,
            'is_active' => true,
        ]);

        VolunteerGateAssignment::create([
            'volunteer_profile_id' => $volunteerProfile->id,
            'gate_id' => $gate->id,
            'event_session_id' => $session->id,
        ]);

        $token = $volunteerUser->createToken('scanner-device', ['scanner']);
        $plainTextToken = $token->plainTextToken;

        $device = CheckInDevice::factory()->create([
            'assigned_volunteer_profile_id' => $volunteerProfile->id,
            'sanctum_token_id' => $token->accessToken->id,
            'status' => 'active',
        ]);

        $scanUuid = (string) Str::uuid();

        $scanPayload = [
            'gate_id' => (string) $gate->id,
            'scans' => [
                [
                    'client_scan_uuid' => $scanUuid,
                    'raw_payload' => $qrCode->payload,
                    'party_size' => 1,
                    'scanned_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $scanResponse = $this->withToken($plainTextToken)
            ->withHeaders(['X-Gate-Id' => $gate->ulid])
            ->postJson(route('scanner.v1.scans.store'), $scanPayload);

        $scanResponse->assertStatus(200)
            ->assertJsonPath('results.0.result', 'admitted');

        // STEP 7: Verify admission state
        $ticket->refresh();

        $this->assertEquals(1, $ticket->admitted_count);
        $this->assertEquals('fully_admitted', $ticket->status);

        // Verify check-in record
        $this->assertDatabaseHas('check_ins', [
            'client_scan_uuid' => $scanUuid,
            'result' => 'admitted',
            'ticket_id' => $ticket->id,
            'attendee_id' => $attendee->id,
        ]);

        // STEP 8: Verify duplicate scan is rejected
        $duplicateScanUuid = (string) Str::uuid();

        $duplicateScanPayload = [
            'gate_id' => (string) $gate->id,
            'scans' => [
                [
                    'client_scan_uuid' => $duplicateScanUuid,
                    'raw_payload' => $qrCode->payload,
                    'party_size' => 1,
                    'scanned_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $duplicateResponse = $this->withToken($plainTextToken)
            ->withHeaders(['X-Gate-Id' => $gate->ulid])
            ->postJson(route('scanner.v1.scans.store'), $duplicateScanPayload);

        $duplicateResponse->assertStatus(200)
            ->assertJsonPath('results.0.result', 'duplicate');

        // Verify no additional admission was recorded
        $ticket->refresh();
        $this->assertEquals(1, $ticket->admitted_count);
        $this->assertEquals('fully_admitted', $ticket->status);
    }

    public function test_flow_with_family_registration(): void
    {
        // Setup family ticket type
        $this->seed(RbacSeeder::class);

        $ticketType = TicketType::factory()->create([
            'name' => 'Family Package',
            'base_price_paisa' => 300000,
            'quantity_total' => 50,
            'quantity_sold' => 0,
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
        ]);

        $session = EventSession::factory()->create([
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(5),
            'is_active' => true,
        ]);

        // Create family registration
        $payload = [
            'full_name' => 'Karim Uddin',
            'mobile' => '+8801812345678',
            'email' => 'karim@example.com',
            'gender' => 'male',
            'participant_type' => 'former_student',
            'ssc_batch_year' => 2015,
            'ticket_type_ulid' => $ticketType->ulid,
            'participation_type' => 'family',
            'adults_count' => 2,
            'children_count' => 1,
            'idempotency_key' => 'test-family-flow-98765',
        ];

        $response = $this->postJson(route('api.v1.public.registrations.store'), $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_payment');

        $registration = Registration::whereHas('attendee', function ($q) {
            $q->where('mobile', '+8801812345678');
        })->first();

        $this->assertEquals(3, $registration->adults_count + $registration->children_count); // 2 adults + 1 child
    }

    public function test_flow_handles_idempotency_key(): void
    {
        $ticketType = TicketType::factory()->create([
            'base_price_paisa' => 100000,
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
        ]);

        $payload = [
            'full_name' => 'Test User',
            'mobile' => '+8801912345678',
            'email' => 'test@example.com',
            'gender' => 'male',
            'participant_type' => 'former_student',
            'ssc_batch_year' => 2012,
            'ticket_type_ulid' => $ticketType->ulid,
            'participation_type' => 'single',
            'adults_count' => 1,
            'children_count' => 0,
            'idempotency_key' => 'test-idempotency-key-11111',
        ];

        // First request
        $firstResponse = $this->postJson(route('api.v1.public.registrations.store'), $payload);
        $firstResponse->assertStatus(201);

        // Different idempotency key should create separate registration
        $payload2 = $payload;
        $payload2['idempotency_key'] = 'test-idempotency-key-22222';
        $payload2['mobile'] = '+8801912345679';

        $secondResponse = $this->postJson(route('api.v1.public.registrations.store'), $payload2);
        $secondResponse->assertStatus(201);

        // Should create two registrations with different idempotency keys
        $this->assertDatabaseCount('registrations', 2);
        $this->assertDatabaseCount('payments', 2);
    }
}
