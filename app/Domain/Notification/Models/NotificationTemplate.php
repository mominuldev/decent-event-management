<?php

namespace App\Domain\Notification\Models;

use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned message body per channel and locale. Data, not code — a Super
 * Admin can fix a typo without a deployment.
 */
class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'channel',
        'locale',
        'version',
        'subject',
        'body',
        'whatsapp_template_name',
        'whatsapp_template_status',
        'variables',
        'estimated_segments',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
