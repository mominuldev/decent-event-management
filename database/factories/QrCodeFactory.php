<?php

namespace Database\Factories;

use App\Domain\Ticketing\Models\QrCode;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QrCode>
 */
class QrCodeFactory extends Factory
{
    protected $model = QrCode::class;

    public function definition(): array
    {
        $payload = 'DTM1.'.(string) Str::ulid().'.1.'.now()->addYear()->timestamp.'.key-1';

        return [
            'ticket_id' => Ticket::factory(),
            'payload_version' => 1,
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'signature' => base64_encode(random_bytes(64)),
            'signing_key_id' => 'key-1',
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ];
    }
}
