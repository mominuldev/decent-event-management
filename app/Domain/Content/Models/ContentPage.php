<?php

namespace App\Domain\Content\Models;

use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasStateMachine;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\ContentPageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An editable marketing page, composed of typed {@see ContentBlock}s.
 *
 * Structured content, not a page builder (docs/08 Phase 3.5 "Scope
 * boundary"): editors fill known fields on known block types and never
 * compose arbitrary layouts.
 */
class ContentPage extends Model
{
    /** @use HasFactory<ContentPageFactory> */
    use HasFactory, HasStateMachine, HasUlid, SoftDeletes;

    /**
     * Draft → review → published (docs/08 Phase 3.5). `published` can return
     * to `draft` so a live page can be pulled without deleting it, and
     * `archived` is the terminal-but-restorable resting state.
     */
    /**
     * Layouts the public site knows how to render. A closed set for the same
     * reason {@see ContentBlock::TYPES} is: `template` picks a renderer on the
     * Next.js side, so an unknown value would publish a blank page.
     *
     * @var list<string>
     */
    public const array TEMPLATES = ['standard', 'landing', 'article', 'contact'];

    public const array TRANSITIONS = [
        'draft' => ['in_review', 'published'],
        'in_review' => ['draft', 'published'],
        'published' => ['draft', 'archived'],
        'archived' => ['draft'],
    ];

    protected $fillable = [
        'slug',
        'template',
        'title',
        'title_bn',
        'excerpt',
        'excerpt_bn',
        'seo_title',
        'seo_title_bn',
        'seo_description',
        'seo_description_bn',
        'og_image_media_id',
        'status',
        'published_at',
        'is_indexable',
        'position',
        'revision_number',
        'created_by_user_id',
        'updated_by_user_id',
        'published_by_user_id',
    ];

    /**
     * The preview token is a shared secret that unlocks unpublished content —
     * it must never reach an API response, so it is hidden by default and
     * left out of `$fillable` (issuing one is an explicit domain action).
     */
    protected $hidden = ['preview_token'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_indexable' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ContentBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class)->orderBy('position');
    }

    /**
     * @return HasMany<ContentPageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ContentPageRevision::class)->orderByDesc('revision_number');
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'og_image_media_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * The one definition of "the public may see this". A page scheduled for a
     * future `published_at` is deliberately still hidden, which is what makes
     * publish-scheduling work without a cron job.
     *
     * @param  Builder<ContentPage>  $query
     * @return Builder<ContentPage>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isLive(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }
}
