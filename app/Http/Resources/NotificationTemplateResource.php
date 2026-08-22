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
            // The public identifier. The auto-increment id stays internal —
            // the templates screen addresses a row by this.
            'ulid' => $this->ulid,
            'key' => $this->key,
            'channel' => $this->channel,
            'locale' => $this->locale,
            'version' => $this->version,
            'subject' => $this->subject,
            // The message itself. Absent until templates became editable,
            // which is why the screen could only ever list them.
            'body' => $this->body,
            // Which `{{placeholders}}` this template may use. Surfacing this
            // is the point: a template written against a variable the
            // dispatching code does not pass renders the `{{key}}` verbatim
            // into a real person's message rather than failing, so the
            // editor has to show what is actually available.
            'variables' => $this->variables ?? [],
            // What one send costs, in SMS segments. Null for email and
            // WhatsApp, which are not billed this way.
            'estimated_segments' => $this->estimated_segments,
            'whatsapp_template_name' => $this->whatsapp_template_name,
            'whatsapp_template_status' => $this->whatsapp_template_status,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
