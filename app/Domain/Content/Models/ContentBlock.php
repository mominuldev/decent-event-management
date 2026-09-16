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
        // History-page sections, on the same principle as the homepage ones:
        // each maps to exactly one bespoke section of the History design.
        // `cta_banner` above is shared — the design binds identical copy to
        // that symbol on both pages — so it is not repeated here.
        'history_hero',
        'founding_story',
        'history_timeline',
        'archive_gallery',
        'numbers_bar',
        'headmaster_message',
        // Events-page sections. `attraction_grid`, `guest_carousel` and
        // `cta_banner` above are shared with the homepage — the same design
        // symbols — so they are not repeated here either.
        'event_hero',
        'programme_glance',
        'full_schedule',
        'venue_directions',
        // Shared inner-page sections. `page_hero` is the plain purple hero
        // every inner page opens with (FAQ, Sponsors, Alumni); pages whose
        // hero carries extra structure — fact cards, contact channels, live
        // counts — get their own hero type below instead.
        'page_hero',
        'faq_contact_cta',
        // Gallery-page sections, one per bespoke section of the Gallery
        // design. `cta_banner` above closes the page.
        'gallery_hero',
        'album_filters',
        'photo_grid',
        'album_collection',
        'video_gallery',
        'contribute',
        // Souvenir-page sections — the commemorative *book*, not merchandise.
        'souvenir_hero',
        'book_preview',
        'book_contents',
        'call_for_writing',
        'editorial_board',
        'get_a_copy',
        // Contact-page sections. `venue_directions` and `faq_list` above are
        // shared with the Events page and the homepage respectively.
        'contact_hero',
        'committee_desks',
        // Tickets-page sections. Prices, admit limits and the free-infant age
        // stay on `ticket_types` — these blocks carry only the copy around
        // them, and the registration form itself is live.
        'tickets_hero',
        'ticket_pricing',
        'pricing_rules',
        'registration_form',
        'how_it_works',
        'ticket_faq',
        // Attendees-page sections. The directory is a live query — the block
        // only places it; the hero's counts come from the same query.
        'attendees_hero',
        'attendee_directory',
        // Site footer, seeded on the `footer` page by FooterSeeder. Not a
        // routed page — the public site reads these blocks into the chrome
        // under every marketing route. One `footer_links` block per column.
        'footer_identity',
        'footer_links',
        'footer_credit',
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
