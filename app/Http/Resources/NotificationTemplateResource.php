<?php

namespace App\Http\Resources;

use App\Domain\Notification\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationTemplate
 */
class NotificationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'channel' => $this->channel,
            'locale' => $this->locale,
            'version' => $this->version,
            'subject' => $this->subject,
            'whatsapp_template_name' => $this->whatsapp_template_name,
            'whatsapp_template_status' => $this->whatsapp_template_status,
            'is_active' => $this->is_active,
        ];
    }
}
