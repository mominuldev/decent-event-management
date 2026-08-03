<?php

namespace App\Domain\Content\Models;

use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasImmutableCreatedAt;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\ContentPageRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only snapshot of a {@see ContentPage} and its full block tree.
 * Never updated after insert — a restore writes a *new* revision rather than
 * rewinding an old one, so the history stays complete.
 */
class ContentPageRevision extends Model
{
    /** @use HasFactory<ContentPageRevisionFactory> */
    use HasFactory, HasImmutableCreatedAt, HasUlid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'content_page_id',
        'revision_number',
        'title',
        'title_bn',
        'excerpt',
        'excerpt_bn',
        'seo_title',
        'seo_title_bn',
        'seo_description',
        'seo_description_bn',
        'blocks_snapshot',
        'status_at_capture',
        'change_note',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'blocks_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContentPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'content_page_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
