<?php

namespace App\Http\Resources;

use App\Domain\Ticketing\Models\QrSigningKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QrSigningKey
 */
class QrSigningKeyResource extends JsonResource
{
    /**
     * The public key is included deliberately — it is not a secret, and an
     * operator confirming a rotation needs to be able to check it against
     * what the manifest is publishing. The private key has no field here
     * because it never reaches the database in the first place.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'key_id' => $this->key_id,
            'public_key' => $this->public_key,
            'status' => $this->status,
            'published_at' => $this->published_at?->toISOString(),
            'activated_at' => $this->activated_at?->toISOString(),
            'retired_at' => $this->retired_at?->toISOString(),
            'published_by' => $this->whenLoaded('publishedBy', fn () => $this->publishedBy?->name),
            'activated_by' => $this->whenLoaded('activatedBy', fn () => $this->activatedBy?->name),
        ];
    }
}
