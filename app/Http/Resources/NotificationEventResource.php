<?php

namespace App\Http\Resources;

use App\Domain\Notification\Models\NotificationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationEvent
 */
class NotificationEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event' => $this->event,
            'provider_status' => $this->provider_status,
            'detail' => $this->detail,
            'occurred_at' => $this->occurred_at->toISOString(),
        ];
    }
}
