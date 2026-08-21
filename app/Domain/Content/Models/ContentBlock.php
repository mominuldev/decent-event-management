<?php

namespace App\Domain\Content\Models;

use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\ContentBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One typed section of a {@see ContentPage}. `data`/`data_bn` hold the
 * editable strings for the block's type; the closed {@see TYPES} list is what
 * keeps this a structured CMS rather than a page builder.
 */
class ContentBlock extends Model
{
    /** @use HasFactory<ContentBlockFactory> */
    use HasFactory, HasUlid;

    /**
     * The block types the editor offers and the public site knows how to
     * render. Adding one means adding a renderer on both sides — it is not a
     * free-form string.
     *
     * @var list<string>
     */
    public const array TYPES = [
        'rich_text',
        'hero',
        'image',
        'cta',
        'stat_row',
        'faq_list',
        'sponsor_grid',
        'schedule',
        'gallery',
        'video',
        // Home-page sections. These are narrower than the generic types
        // above on purpose: each one maps to exactly one bespoke section of
        // the centenary homepage design, so the editor fills the fields that
        // section actually draws rather than approximating it with a
        // `rich_text` + `image` pair the renderer would have to guess at.
        'home_hero',
        'stat_bar',
        'history_teaser',
        'milestone_timeline',
        'guest_carousel',
        'attraction_grid',
        'testimonial_carousel',
        'pricing_teaser',
        'cta_banner',
    ];

    protected $fillable = [
        'content_page_id',
        'type',
        'position',
        'data',
        'data_bn',
        'media_id',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'data_bn' => 'array',
            'is_visible' => 'boolean',
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
     * @return BelongsTo<MediaFile, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_id');
    }
}
