<?php

namespace Tests\Feature\Admin;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Database\Seeders\TicketTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registering somebody at a desk and taking their money in cash.
 *
 * The invariants these exist to hold:
 *
 *  - Cash settles **in full or not at all**, and the amount is compared
 *    against what is due rather than trusted.
 *  - Cash never settles a payment with a live gateway session, or the
 *    attendee can be charged twice for one seat.
 *  - Settling issues the ticket and sends the confirmation — that is the
 *    whole point of the feature, and it is what a test asserting only
 *    `status === 'succeeded'` would miss.
 *  - A counter registration does not also send "complete payment to
 *    confirm your seat", which is false the moment the money is in the till.
 */
class AdminCashRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(TicketTypeSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active', 'name' => 'Desk Staff']);
        $this->admin->assignRole('Super Admin');
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
    }

    private function centennialType(): TicketType
    {
        return TicketType::where('code', 'CEN')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Rahim Uddin',
            'full_name_bn' => 'রহিম উদ্দিন',
            'father_name' => 'Abdul Karim',
            'mobile' => '01712345678',
            'email' => 'rahim@example.com',
            'gender' => 'male',
            'occupation' => 'Engineer',
            'current_address' => 'House 12, Road 5, Dhanmondi, Dhaka',
            'participant_type' => 'former_student',
            'ssc_batch_year' => 2004,
            'ticket_type_ulid' => $this->centennialType()->ulid,
            'participation_type' => 'single',
            'adults_count' => 1,
            'children_count' => 0,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createAtCounter(array $payload = [], ?string $idempotencyKey = null): TestResponse
    {
        return $this->postJson(
            route('api.v1.admin.registrations.store'),
            $payload === [] ? $this->payload() : $payload,
            ['Idempotency-Key' => $idempotencyKey ?? (string) Str::ulid()],
        );
    }

    // ---------------------------------------------------------------- create

    public function test_an_admin_registers_an_attendee_at_the_counter_with_a_pending_cash_payment(): void
    {
        $this->actAsAdmin();

        $response = $this->createAtCounter();

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.source', 'admin_counter')
            ->assertJsonPath('data.total_paisa', 250000)
            ->assertJsonPath('data.payments.0.method', 'cash')
            ->assertJsonPath('data.payments.0.channel', 'manual')
            ->assertJsonPath('data.payments.0.status', 'pending');

        $registration = Registration::where('ulid', $response->json('data.ulid'))->firstOrFail();

        $this->assertSame($this->admin->id, $registration->created_by_user_id);

        // No reservation TTL. A sweeper that expired this would release a
        // seat somebody is standing at the desk paying for.
        $this->assertNull($registration->payments()->firstOrFail()->expires_at);
    }

    public function test_the_counter_payment_is_invisible_to_the_expiry_sweeper_and_the_reconciler(): void
    {
        // Both select on `channel != 'manual'` and then hand the row's method
        // to PaymentGatewayResolver, which throws for `cash`. Getting the
        // channel wrong is not cosmetic: it either kills a paid registration
        // or crashes the nightly sweep.
        $this->actAsAdmin();
        $this->createAtCounter()->assertStatus(201);

        $swept = Payment::query()
            ->where('status', 'pending')
            ->where('channel', '!=', 'manual')
            ->count();

        $this->assertSame(0, $swept);
    }

    public function test_the_counter_registration_is_audited_against_the_member_of_staff_who_took_it(): void
    {
        $this->actAsAdmin();
        $response = $this->createAtCounter();

        $registration = Registration::where('ulid', $response->json('data.ulid'))->firstOrFail();

        $log = ActivityLog::where('log_name', 'registration')
            ->where('event', 'created')
            ->where('subject_id', $registration->id)
            ->firstOrFail();

        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame('admin_counter', $log->properties['source']);
        $this->assertSame(250000, $log->properties['total_paisa']);
    }

    public function test_a_public_registration_writes_no_such_audit_row(): void
    {
        // 20,000 self-registrations would drown the one table whose value is
        // that every row is somebody's deliberate act on another's behalf.
        $this->postJson(
            route('api.v1.public.registrations.store'),
            $this->payload(['idempotency_key' => (string) Str::ulid()]),
            ['Idempotency-Key' => (string) Str::ulid()],
        )->assertStatus(201);

        $this->assertSame(0, ActivityLog::where('log_name', 'registration')->where('event', 'created')->count());
    }

    public function test_a_replayed_idempotency_key_returns_the_original_registration_rather_than_a_second_one(): void
    {
        $this->actAsAdmin();

        $key = (string) Str::ulid();
        $payload = $this->payload();

        $first = $this->createAtCounter($payload, $key)->assertStatus(201);
        $second = $this->createAtCounter($payload, $key)->assertStatus(201);

        $this->assertSame($first->json('data.ulid'), $second->json('data.ulid'));
        $this->assertSame(1, Registration::count());
    }

    public function test_creating_without_an_idempotency_key_is_refused(): void
    {
        $this->actAsAdmin();

        $this->postJson(route('api.v1.admin.registrations.store'), $this->payload())
            ->assertStatus(400);

        $this->assertSame(0, Registration::count());
    }

    public function test_the_counter_form_requires_the_same_four_profile_fields_the_public_form_does(): void
    {
        $this->actAsAdmin();

        $payload = $this->payload();
        unset($payload['full_name_bn'], $payload['father_name'], $payload['occupation'], $payload['current_address']);

        $this->createAtCounter($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name_bn', 'father_name', 'occupation', 'current_address']);
    }

    public function test_a_second_registration_for_the_same_person_is_refused_and_names_the_one_that_exists(): void
    {
        $this->actAsAdmin();

        $first = $this->createAtCounter()->assertStatus(201);
        $existingNumber = $first->json('data.registration_number');

        $soldBefore = $this->centennialType()->refresh()->quantity_reserved;

        $response = $this->createAtCounter($this->payload(['email' => 'rahim.second@example.com']));

        $response->assertStatus(422)->assertJsonPath('code', 'already_registered');

        // The operator has to be told *which* record to collect against, or
        // they are left to go and search for it mid-queue.
        $this->assertStringContainsString((string) $existingNumber, (string) $response->json('message'));
        $this->assertStringContainsString('pending_payment', (string) $response->json('message'));

        // And the refusal must not have taken a seat on the way out.
        $this->assertSame($soldBefore, $this->centennialType()->refresh()->quantity_reserved);
    }

    // --------------------------------------------------------------- collect

    public function test_collecting_cash_settles_the_payment_issues_the_ticket_and_sends_the_confirmation(): void
    {
        $this->seedTicketDeliveredTemplates();

        $this->actAsAdmin();
        $created = $this->createAtCounter()->assertStatus(201);

        $registration = Registration::where('ulid', $created->json('data.ulid'))->firstOrFail();
        $payment = $registration->payments()->firstOrFail();

        $response = $this->postJson(
            route('api.v1.admin.payments.collect-cash', ['payment' => $payment->ulid]),
            ['amount_received_paisa' => 250000, 'receipt_reference' => 'RCPT-0042'],
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('data.channel', 'manual')
            ->assertJsonPath('data.amount_paid_paisa', 250000);

        $payment->refresh();
        $this->assertNotNull($payment->paid_at);
        $this->assertSame($this->admin->id, $payment->verified_by_user_id);
        $this->assertNull($payment->expires_at);
        $this->assertStringContainsString('RCPT-0042', (string) $payment->verification_note);

        // The ticket is the deliverable, not the payment row. A test that
        // stopped at `succeeded` would pass with issuance entirely broken.
        $ticket = Ticket::where('registration_id', $registration->id)->firstOrFail();
        $this->assertSame('active', $ticket->status);
        $this->assertNotNull($ticket->qrCode);
        $this->assertTrue((bool) $ticket->qrCode?->is_active);
        $this->assertSame('confirmed', $registration->refresh()->status);

        $channels = Notification::where('template_key', 'ticket_delivered')->pluck('channel')->all();
        sort($channels);
        $this->assertSame(['email', 'sms'], $channels);
    }

    public function test_a_counter_sale_does_not_also_send_complete_your_payment(): void
    {
        // The seeded copy reads "Complete payment to confirm your seat",
        // which is false by the time it would arrive at a desk.
        NotificationTemplate::factory()->create([
            'key' => 'registration_received', 'channel' => 'email', 'locale' => 'en',
            'version' => 1, 'subject' => 's', 'body' => 'b', 'is_active' => true,
        ]);

        $this->actAsAdmin();
        $this->createAtCounter()->assertStatus(201);

        $this->assertSame(0, Notification::where('template_key', 'registration_received')->count());
    }

    public function test_a_public_registration_still_gets_it(): void
    {
        NotificationTemplate::factory()->create([
            'key' => 'registration_received', 'channel' => 'email', 'locale' => 'en',
            'version' => 1, 'subject' => 's', 'body' => 'b', 'is_active' => true,
        ]);

        $this->postJson(
            route('api.v1.public.registrations.store'),
            $this->payload(['idempotency_key' => (string) Str::ulid()]),
            ['Idempotency-Key' => (string) Str::ulid()],
        )->assertStatus(201);

        $this->assertSame(1, Notification::where('template_key', 'registration_received')->count());
    }

    public function test_the_wrong_amount_is_refused_and_the_payment_is_left_alone(): void
    {
        $this->actAsAdmin();
        $created = $this->createAtCounter()->assertStatus(201);
        $payment = Registration::where('ulid', $created->json('data.ulid'))->firstOrFail()->payments()->firstOrFail();

        $response = $this->postJson(
            route('api.v1.admin.payments.collect-cash', ['payment' => $payment->ulid]),
            ['amount_received_paisa' => 200000],
        );

        $response->assertStatus(422)
            ->assertJsonPath('code', 'cash_amount_mismatch')
            ->assertJsonValidationErrors(['amount_received_paisa']);

        // Both figures named, so the operator knows what to take rather than
        // being told only that they were wrong.
        $this->assertStringContainsString('2,000.00', (string) $response->json('message'));
        $this->assertStringContainsString('2,500.00', (string) $response->json('message'));

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame(0, (int) $payment->amount_paid_paisa);
        $this->assertSame(0, Ticket::count());
    }

    public function test_cash_cannot_settle_a_payment_with_a_live_gateway_session(): void
    {
        $this->actAsAdmin();
        $created = $this->createAtCounter()->assertStatus(201);
        $payment = Registration::where('ulid', $created->json('data.ulid'))->firstOrFail()->payments()->firstOrFail();

        $payment->forceFill(['status' => 'initiated'])->save();

        $this->postJson(
            route('api.v1.admin.payments.collect-cash', ['payment' => $payment->ulid]),
            ['amount_received_paisa' => 250000],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'payment_not_collectable');

        $this->assertSame('initiated', $payment->refresh()->status);
        $this->assertSame(0, Ticket::count());
    }

    public function test_an_already_settled_payment_cannot_be_collected_twice(): void
    {
        $this->actAsAdmin();
        $created = $this->createAtCounter()->assertStatus(201);
        $payment = Registration::where('ulid', $created->json('data.ulid'))->firstOrFail()->payments()->firstOrFail();

        $url = route('api.v1.admin.payments.collect-cash', ['payment' => $payment->ulid]);

        $this->postJson($url, ['amount_received_paisa' => 250000])->assertStatus(200);
        $this->postJson($url, ['amount_received_paisa' => 250000])
            ->assertStatus(422)
            ->assertJsonPath('code', 'payment_not_collectable');

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, $payment->refresh()->transactions()->where('type', 'collect')->count());
    }

    public function test_a_walk_in_may_pay_cash_for_an_online_booking_they_abandoned(): void
    {
        // The registration exists, the gateway was never reached, and the
        // person turns up with notes. The original method is kept on the
        // transaction row so a till can still be reconciled against it.
        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $this->centennialType()->id,
            'status' => 'pending_payment',
        ]);
        $payment = Payment::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'method' => 'paystation',
            'channel' => 'online',
            'status' => 'pending',
            'amount_due_paisa' => 250000,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actAsAdmin();

        $this->postJson(
            route('api.v1.admin.payments.collect-cash', ['payment' => $payment->ulid]),
            ['amount_received_paisa' => 250000],
        )
            ->assertStatus(200)
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('data.channel', 'manual');

        $transaction = $payment->refresh()->transactions()->where('type', 'collect')->firstOrFail();
        $this->assertSame('paystation', $transaction->request_payload['previous_method']);
        $this->assertSame('online', $transaction->request_payload['previous_channel']);

        $this->assertSame(1, Ticket::where('registration_id', $registration->id)->count());
    }

    public function test_collecting_cash_is_audited_from_the_action(): void
    {
        $this->actAsAdmin();
        $created = $this->createAtCounter()->assertStatus(201);
        $registration = Registration::where('ulid', $created->json('data.ulid'))->firstOrFail();
        $payment = $registration->payments()->firstOrFail();

        $this->postJson(
            route('api.v1.admin.payments.collect-cash', ['payment' => $payment->ulid]),
            ['amount_received_paisa' => 250000, 'receipt_reference' => 'RCPT-7', 'note' => 'Paid at gate desk'],
        )->assertStatus(200);

        $log = ActivityLog::where('log_name', 'payment')->where('event', 'cash_collected')->firstOrFail();

        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame($payment->id, $log->subject_id);
        $this->assertSame(250000, $log->properties['amount_paisa']);
        $this->assertSame('RCPT-7', $log->properties['receipt_reference']);
        $this->assertSame($registration->registration_number, $log->properties['registration_number']);
    }

    // ------------------------------------------------------------ permission

    public function test_creating_at_the_counter_needs_registration_create(): void
    {
        $denied = User::factory()->create(['status' => 'active']);
        $denied->assignRole('Volunteer');
        Sanctum::actingAs($denied, ['admin'], 'web-admin');

        $this->createAtCounter()->assertStatus(403);
        $this->assertSame(0, Registration::count());
    }

    public function test_collecting_cash_needs_payment_collect_cash(): void
    {
        $this->actAsAdmin();
        $created = $this->createAtCounter()->assertStatus(201);
        $payment = Registration::where('ulid', $created->json('data.ulid'))->firstOrFail()->payments()->firstOrFail();

        $denied = User::factory()->create(['status' => 'active']);
        $denied->assignRole('Volunteer');
        Sanctum::actingAs($denied, ['admin'], 'web-admin');

        $this->postJson(
            route('api.v1.admin.payments.collect-cash', ['payment' => $payment->ulid]),
            ['amount_received_paisa' => 250000],
        )->assertStatus(403);

        $this->assertSame('pending', $payment->refresh()->status);
    }

    private function seedTicketDeliveredTemplates(): void
    {
        foreach ([['email', 'bn'], ['sms', 'en']] as [$channel, $locale]) {
            NotificationTemplate::factory()->create([
                'key' => 'ticket_delivered',
                'channel' => $channel,
                'locale' => $locale,
                'version' => 1,
                'subject' => 'Ticket {{ticket_number}}',
                'body' => 'Ticket {{ticket_number}} for {{full_name}}',
                'is_active' => true,
            ]);
        }
    }
}
