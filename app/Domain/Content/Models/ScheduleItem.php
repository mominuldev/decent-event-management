<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Support\IsPublishableContent;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\ScheduleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published agenda entry. `event_session_code` is a soft reference to
 * CheckIn's `event_sessions.code` and deliberately not a foreign key — the
 * module-boundary rule (CLAUDE.md) forbids Content reaching into another
 * module's tables, and published marketing copy must survive a session being
 * renamed or removed.
 */
class ScheduleItem extends Model
{
    /** @use HasFactory<ScheduleItemFactory> */
    use HasFactory, HasUlid, IsPublishableContent;

    protected $fillable = [
        'title',
        'title_bn',
        'description',
        'description_bn',
        'speaker_name',
        'speaker_name_bn',
        'speaker_title',
        'speaker_title_bn',
        'speaker_photo_media_id',
        'venue',
        'venue_bn',
        'track',
        'starts_at',
        'ends_at',
        'event_session_code',
        'position',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function speakerPhoto(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'speaker_photo_media_id');
    }
}
