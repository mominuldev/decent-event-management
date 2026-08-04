<?php

namespace Database\Factories;

use App\Domain\Ticketing\Models\QrCode;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\QrSigner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QrCode>
 */
class QrCodeFactory extends Factory
{
    protected $model = QrCode::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'payload_version' => 1,
            // Left blank here and genuinely signed against the real ticket
            // ulid in configure()'s afterMaking() below, once the ticket
            // relation is resolvable — a factory-default random signature
            // would fail verification now that ProcessCheckIn actually
            // checks it. A caller that explicitly overrides `payload`
            // (e.g. to test a forged/malformed scan) is left alone.
            'payload' => '',
            'payload_hash' => '',
            'signature' => '',
            'signing_key_id' => '',
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (QrCode $qrCode): void {
            if ($qrCode->payload !== '') {
                return;
            }

            $ticket = Ticket::find($qrCode->ticket_id);
            $admitsTotal = $ticket !== null ? $ticket->admits_total : 1;
            $ticketUlid = $ticket !== null ? $ticket->ulid : (string) $qrCode->ticket_id;
            $expiresAtUnix = (int) ($qrCode->expires_at ?? now()->addYear())->timestamp;

            $signed = app(QrSigner::class)->sign($ticketUlid, $admitsTotal, $expiresAtUnix);

            $qrCode->forceFill($signed);
        });
    }
}
