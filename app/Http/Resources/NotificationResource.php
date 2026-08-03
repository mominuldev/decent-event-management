<?php

namespace App\Http\Resources;

use App\Domain\Notification\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'notifiable_type' => $this->notifiable_type,
            'notifiable_id' => $this->notifiable_id,
            'channel' => $this->channel,
            'template_key' => $this->template_key,
            'locale' => $this->locale,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'provider' => $this->provider,
            'provider_message_id' => $this->provider_message_id,
            'segment_count' => $this->segment_count,
            'cost_paisa' => $this->cost_paisa,
            'last_error' => $this->last_error,
            'scheduled_for' => $this->scheduled_for?->toISOString(),
            'sent_at' => $this->sent_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'events' => NotificationEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
