<?php

namespace Tests\Feature\Admin;

use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('Super Admin');
    }

    public function test_admin_can_verify_manual_payment(): void
    {
        $attendee = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create(['quantity_reserved' => 1, 'quantity_sold' => 0]);
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'pending_payment',
        ]);

        $payment = Payment::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'status' => 'awaiting_verification',
            'manual_trx_id' => 'TRX12345678',
            'amount_due_paisa' => 100000,
        ]);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $response = $this->postJson(route('api.v1.admin.payments.verify-manual', ['payment' => $payment->ulid]), [
            'verification_note' => 'Bank statement matches',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'succeeded',
        ]);

        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('tickets', [
            'registration_id' => $registration->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_void_ticket(): void
    {
        $attendee = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create();
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'confirmed',
        ]);
        $ticket = Ticket::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $response = $this->postJson(route('api.v1.admin.tickets.void', ['ticket' => $ticket->ulid]), [
            'void_reason' => 'Duplicate issue',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => 'voided',
            'void_reason' => 'Duplicate issue',
        ]);
    }

    public function test_admin_can_trigger_report_export(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $response = $this->postJson(route('api.v1.admin.reports.export', ['reportKey' => 'registrations_by_batch']), [
            'format' => 'xlsx',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.report_key', 'registrations_by_batch')
            ->assertJsonPath('data.status', 'queued');
    }
}
