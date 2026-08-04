<?php

namespace Tests\Unit\Domain\CheckIn;

use App\Domain\CheckIn\Actions\ProcessCheckIn;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\QrSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stages 1-2 of the four-stage scan decision (docs/01 §1.5, docs/08 Phase
 * 6): format and Ed25519 signature verification, upstream of
 * AdmissionPolicy. Closes the gap flagged in CLAUDE.md /
 * ProcessCheckIn.php:176 — `signature_valid` used to be hardcoded true
 * and nothing was actually checked.
 */
class ProcessCheckInSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_genuinely_signed_payload_admits(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active', 'admits_total' => 2, 'admitted_count' => 0]);
        $gate = Gate::factory()->create();
        $payload = $this->signedPayloadFor($ticket);

        $checkIn = app(ProcessCheckIn::class)->execute(
            clientScanUuid: (string) Str::uuid(),
            rawPayload: $payload,
            partySize: 1,
            gate: $gate,
        );

        $this->assertSame('admitted', $checkIn->result);
        $this->assertTrue($checkIn->signature_valid);
    }

    public function test_a_forged_signature_is_rejected_and_never_reaches_admission_policy(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active', 'admits_total' => 2, 'admitted_count' => 0]);
        $gate = Gate::factory()->create();
        $exp = now()->addYear()->timestamp;
        $forged = "DTM1.{$ticket->ulid}.2.{$exp}.".app(QrSigner::class)->activeKeyId().'.'.str_repeat('A', 86);

        $checkIn = app(ProcessCheckIn::class)->execute(
            clientScanUuid: (string) Str::uuid(),
            rawPayload: $forged,
            partySize: 1,
            gate: $gate,
        );

        $this->assertSame('invalid_signature', $checkIn->result);
        $this->assertFalse($checkIn->signature_valid);
        $this->assertSame(0, $ticket->fresh()->admitted_count);
    }

    public function test_knowing_the_ticket_ulid_alone_is_not_enough_to_gain_admission(): void
    {
        // The historical gap: ProcessCheckIn used to accept any string
        // matching the DTM1 shape whose second segment was a real ticket
        // ulid — meaning the ulid alone (which appears in admin/attendee
        // URLs) was a valid credential. It must not be anymore.
        $ticket = Ticket::factory()->create(['status' => 'active', 'admits_total' => 2, 'admitted_count' => 0]);
        $gate = Gate::factory()->create();
        $handCrafted = "DTM1.{$ticket->ulid}.2.".now()->addYear()->timestamp.'.key-1.not-a-real-signature-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

        $checkIn = app(ProcessCheckIn::class)->execute(
            clientScanUuid: (string) Str::uuid(),
            rawPayload: $handCrafted,
            partySize: 1,
            gate: $gate,
        );

        $this->assertNotSame('admitted', $checkIn->result);
    }

    public function test_an_expired_payload_is_rejected_even_with_a_valid_signature(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'active', 'admits_total' => 2, 'admitted_count' => 0]);
        $gate = Gate::factory()->create();
        $signed = app(QrSigner::class)->sign($ticket->ulid, 2, now()->subDay()->timestamp);

        $checkIn = app(ProcessCheckIn::class)->execute(
            clientScanUuid: (string) Str::uuid(),
            rawPayload: $signed['payload'],
            partySize: 1,
            gate: $gate,
        );

        $this->assertSame('expired', $checkIn->result);
        $this->assertTrue($checkIn->signature_valid);
    }

    public function test_malformed_payload_is_rejected_as_invalid_format(): void
    {
        $gate = Gate::factory()->create();

        $checkIn = app(ProcessCheckIn::class)->execute(
            clientScanUuid: (string) Str::uuid(),
            rawPayload: 'not-a-qr-payload-at-all',
            partySize: 1,
            gate: $gate,
        );

        $this->assertSame('invalid_format', $checkIn->result);
    }

    public function test_manual_override_bypasses_signature_verification(): void
    {
        // Mirrors the synthetic marker payload CheckInController's manual
        // override builds — no real signature exists for it, by design.
        $ticket = Ticket::factory()->create(['status' => 'active', 'admits_total' => 2, 'admitted_count' => 0]);
        $gate = Gate::factory()->create();
        $syntheticPayload = 'DTM1.'.$ticket->ulid.'.admin-override.0.0.0';

        $checkIn = app(ProcessCheckIn::class)->execute(
            clientScanUuid: (string) Str::uuid(),
            rawPayload: $syntheticPayload,
            partySize: 1,
            gate: $gate,
            isManualOverride: true,
        );

        $this->assertSame('manual_override', $checkIn->result);
        $this->assertNull($checkIn->signature_valid);
    }

    private function signedPayloadFor(Ticket $ticket): string
    {
        return app(QrSigner::class)->sign($ticket->ulid, $ticket->admits_total, now()->addYear()->timestamp)['payload'];
    }
}
