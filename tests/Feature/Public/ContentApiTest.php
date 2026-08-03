<?php

namespace Tests\Feature\Public;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\Faq;
use App\Domain\Content\Models\GalleryAlbum;
use App\Domain\Content\Models\GalleryItem;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;
use App\Domain\Content\Models\ScheduleItem;
use App\Domain\Content\Models\Sponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public CMS read API (docs/08 Phase 3.5).
 *
 * The two rules worth breaking the build over: unpublished content is
 * unreachable without a valid preview token and answers 404 rather than 403,
 * and Bangla survives the whole path from database to response body.
 */
class ContentApiTest extends TestCase
{
    use RefreshDatabase;

    // === Visibility ===

    public function test_live_page_is_returned_with_its_visible_blocks(): void
    {
        $page = ContentPage::factory()->published()->create(['slug' => 'about']);
        ContentBlock::factory()->for($page, 'page')->create(['position' => 0, 'data' => ['body' => 'First']]);
        ContentBlock::factory()->for($page, 'page')->create(['position' => 1, 'data' => ['body' => 'Second']]);
        ContentBlock::factory()->for($page, 'page')->hidden()->create(['position' => 2, 'data' => ['body' => 'Hidden']]);

        $response = $this->getJson('/api/v1/public/content/pages/about');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'about')
            ->assertJsonCount(2, 'data.blocks')
            ->assertJsonPath('data.blocks.0.data.body', 'First')
            ->assertJsonPath('data.blocks.1.data.body', 'Second');
    }

    public function test_draft_page_returns_404_not_403(): void
    {
        ContentPage::factory()->create(['slug' => 'secret-draft', 'status' => 'draft']);

        $this->getJson('/api/v1/public/content/pages/secret-draft')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_in_review_page_returns_404(): void
    {
        ContentPage::factory()->inReview()->create(['slug' => 'under-review']);

        $this->getJson('/api/v1/public/content/pages/under-review')->assertNotFound();
    }

    public function test_archived_page_returns_404(): void
    {
        ContentPage::factory()->archived()->create(['slug' => 'old-page', 'published_at' => now()->subYear()]);

        $this->getJson('/api/v1/public/content/pages/old-page')->assertNotFound();
    }

    public function test_page_scheduled_for_the_future_stays_hidden_until_its_time(): void
    {
        ContentPage::factory()->scheduled()->create(['slug' => 'launch-day']);

        $this->getJson('/api/v1/public/content/pages/launch-day')->assertNotFound();

        // Same row, same status — only the clock moved.
        $this->travelTo(now()->addDays(8));

        $this->getJson('/api/v1/public/content/pages/launch-day')->assertOk();
    }

    public function test_soft_deleted_page_returns_404(): void
    {
        $page = ContentPage::factory()->published()->create(['slug' => 'removed']);
        $page->delete();

        $this->getJson('/api/v1/public/content/pages/removed')->assertNotFound();
    }

    public function test_a_missing_page_and_a_draft_page_are_indistinguishable(): void
    {
        ContentPage::factory()->create(['slug' => 'exists-but-draft', 'status' => 'draft']);

        $draft = $this->getJson('/api/v1/public/content/pages/exists-but-draft');
        $missing = $this->getJson('/api/v1/public/content/pages/never-existed');

        $this->assertSame($draft->getStatusCode(), $missing->getStatusCode());
        // `request_id` differs per request by design; everything else must match,
        // or the response shape itself becomes a probe for draft slugs.
        $this->assertSame(
            array_diff_key($draft->json(), ['request_id' => null]),
            array_diff_key($missing->json(), ['request_id' => null]),
        );
    }

    public function test_page_index_lists_only_live_pages(): void
    {
        ContentPage::factory()->published()->create(['slug' => 'live-one']);
        ContentPage::factory()->create(['slug' => 'draft-one', 'status' => 'draft']);
        ContentPage::factory()->scheduled()->create(['slug' => 'future-one']);

        $response = $this->getJson('/api/v1/public/content/pages');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('live-one', $response->json('data.0.slug'));
    }

    // === Preview tokens ===

    public function test_valid_preview_token_reveals_an_unpublished_page(): void
    {
        ContentPage::factory()
            ->withPreviewToken('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6')
            ->create(['slug' => 'draft-page', 'status' => 'draft', 'title' => 'Work in progress']);

        $this->getJson('/api/v1/public/content/pages/draft-page?preview_token=a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6')
            ->assertOk()
            ->assertJsonPath('data.title', 'Work in progress');
    }

    public function test_wrong_preview_token_returns_404(): void
    {
        ContentPage::factory()
            ->withPreviewToken('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6')
            ->create(['slug' => 'draft-page', 'status' => 'draft']);

        $this->getJson('/api/v1/public/content/pages/draft-page?preview_token=00000000000000000000000000000000')
            ->assertNotFound();
    }

    public function test_empty_preview_token_does_not_match_a_page_without_one(): void
    {
        ContentPage::factory()->create(['slug' => 'no-token-page', 'status' => 'draft']);

        $this->getJson('/api/v1/public/content/pages/no-token-page?preview_token=')->assertNotFound();
        $this->getJson('/api/v1/public/content/pages/no-token-page')->assertNotFound();
    }

    public function test_preview_responses_are_never_cached_or_indexed(): void
    {
        ContentPage::factory()
            ->withPreviewToken('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6')
            ->create(['slug' => 'draft-page', 'status' => 'draft']);

        $response = $this->getJson('/api/v1/public/content/pages/draft-page?preview_token=a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6');

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
    }

    public function test_preview_token_is_never_exposed_in_a_response(): void
    {
        ContentPage::factory()
            ->published()
            ->withPreviewToken('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6')
            ->create(['slug' => 'live-page']);

        $this->getJson('/api/v1/public/content/pages/live-page')
            ->assertOk()
            ->assertDontSee('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6');
    }

    // === Bilingual rendering ===

    public function test_locale_query_parameter_returns_bangla(): void
    {
        ContentPage::factory()->published()->create([
            'slug' => 'about',
            'title' => 'About',
            'title_bn' => 'পরিচিতি',
        ]);

        $this->getJson('/api/v1/public/content/pages/about?locale=bn')
            ->assertOk()
            ->assertJsonPath('data.locale', 'bn')
            ->assertJsonPath('data.title', 'পরিচিতি');
    }

    public function test_accept_language_header_selects_bangla(): void
    {
        ContentPage::factory()->published()->create([
            'slug' => 'about',
            'title' => 'About',
            'title_bn' => 'পরিচিতি',
        ]);

        $this->getJson('/api/v1/public/content/pages/about', ['Accept-Language' => 'bn-BD,bn;q=0.9,en;q=0.8'])
            ->assertOk()
            ->assertJsonPath('data.title', 'পরিচিতি');
    }

    public function test_explicit_locale_beats_the_accept_language_header(): void
    {
        ContentPage::factory()->published()->create([
            'slug' => 'about',
            'title' => 'About',
            'title_bn' => 'পরিচিতি',
        ]);

        $this->getJson('/api/v1/public/content/pages/about?locale=en', ['Accept-Language' => 'bn'])
            ->assertOk()
            ->assertJsonPath('data.title', 'About');
    }

    public function test_bangla_request_falls_back_to_english_for_untranslated_fields(): void
    {
        ContentPage::factory()->published()->englishOnly()->create([
            'slug' => 'about',
            'title' => 'About',
        ]);

        $this->getJson('/api/v1/public/content/pages/about?locale=bn')
            ->assertOk()
            ->assertJsonPath('data.locale', 'bn')
            ->assertJsonPath('data.title', 'About');
    }

    public function test_block_data_falls_back_per_key_when_partially_translated(): void
    {
        $page = ContentPage::factory()->published()->create(['slug' => 'about']);
        ContentBlock::factory()->for($page, 'page')->create([
            'data' => ['heading' => 'Our history', 'body' => 'A century of teaching.'],
            // Only the heading is translated.
            'data_bn' => ['heading' => 'আমাদের ইতিহাস'],
        ]);

        $this->getJson('/api/v1/public/content/pages/about?locale=bn')
            ->assertOk()
            ->assertJsonPath('data.blocks.0.data.heading', 'আমাদের ইতিহাস')
            ->assertJsonPath('data.blocks.0.data.body', 'A century of teaching.');
    }

    public function test_an_unsupported_locale_falls_back_to_english(): void
    {
        ContentPage::factory()->published()->create([
            'slug' => 'about',
            'title' => 'About',
            'title_bn' => 'পরিচিতি',
        ]);

        $this->getJson('/api/v1/public/content/pages/about?locale=fr')
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.title', 'About');
    }

    public function test_bangla_survives_the_round_trip_through_the_database(): void
    {
        $bangla = 'শতবর্ষ উদযাপন — ১০০ বছর';

        ContentPage::factory()->published()->create(['slug' => 'home', 'title_bn' => $bangla]);

        $stored = ContentPage::where('slug', 'home')->firstOrFail();
        $this->assertSame($bangla, $stored->title_bn);

        $this->getJson('/api/v1/public/content/pages/home?locale=bn')
            ->assertOk()
            ->assertJsonPath('data.title', $bangla);
    }

    // === Caching ===

    public function test_etag_revalidation_returns_304(): void
    {
        ContentPage::factory()->published()->create(['slug' => 'about']);

        $first = $this->getJson('/api/v1/public/content/pages/about')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->getJson('/api/v1/public/content/pages/about', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_etag_differs_between_locales(): void
    {
        ContentPage::factory()->published()->create([
            'slug' => 'about',
            'title' => 'About',
            'title_bn' => 'পরিচিতি',
        ]);

        $english = $this->getJson('/api/v1/public/content/pages/about?locale=en')->headers->get('ETag');
        $bangla = $this->getJson('/api/v1/public/content/pages/about?locale=bn')->headers->get('ETag');

        $this->assertNotSame($english, $bangla);
    }

    public function test_a_stale_etag_gets_a_fresh_body(): void
    {
        $page = ContentPage::factory()->published()->create(['slug' => 'about', 'title' => 'Before']);

        $etag = $this->getJson('/api/v1/public/content/pages/about')->headers->get('ETag');

        $page->update(['title' => 'After']);

        $this->getJson('/api/v1/public/content/pages/about', ['If-None-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.title', 'After');
    }

    public function test_public_content_varies_on_accept_language(): void
    {
        ContentPage::factory()->published()->create(['slug' => 'about']);

        $response = $this->getJson('/api/v1/public/content/pages/about')->assertOk();

        $this->assertStringContainsString('Accept-Language', (string) $response->headers->get('Vary'));
    }

    // === Menus ===

    public function test_menu_nests_children_in_position_order(): void
    {
        $menu = Menu::factory()->create(['code' => 'primary']);
        $about = MenuItem::factory()->for($menu)->create(['label' => 'About', 'position' => 0]);
        MenuItem::factory()->for($menu)->create(['label' => 'History', 'position' => 1, 'parent_id' => $about->id]);
        MenuItem::factory()->for($menu)->create(['label' => 'Contact', 'position' => 1]);

        $response = $this->getJson('/api/v1/public/content/menus/primary');

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.label', 'About')
            ->assertJsonPath('data.items.0.children.0.label', 'History')
            ->assertJsonPath('data.items.1.label', 'Contact')
            ->assertJsonCount(0, 'data.items.1.children');
    }

    public function test_menu_drops_items_linked_to_a_page_that_is_not_live(): void
    {
        $menu = Menu::factory()->create(['code' => 'primary']);
        $livePage = ContentPage::factory()->published()->create(['slug' => 'about']);
        $draftPage = ContentPage::factory()->create(['slug' => 'draft', 'status' => 'draft']);

        MenuItem::factory()->for($menu)->create(['label' => 'About', 'position' => 0, 'content_page_id' => $livePage->id, 'url' => null]);
        MenuItem::factory()->for($menu)->create(['label' => 'Draft', 'position' => 1, 'content_page_id' => $draftPage->id, 'url' => null]);

        $response = $this->getJson('/api/v1/public/content/menus/primary');

        $response->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertSame('/about', $response->json('data.items.0.url'));
    }

    public function test_hidden_menu_items_are_omitted(): void
    {
        $menu = Menu::factory()->create(['code' => 'primary']);
        MenuItem::factory()->for($menu)->create(['label' => 'Visible', 'position' => 0]);
        MenuItem::factory()->for($menu)->hidden()->create(['label' => 'Hidden', 'position' => 1]);

        $this->getJson('/api/v1/public/content/menus/primary')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.label', 'Visible');
    }

    public function test_inactive_menu_returns_404(): void
    {
        Menu::factory()->inactive()->create(['code' => 'retired']);

        $this->getJson('/api/v1/public/content/menus/retired')->assertNotFound();
    }

    // === Sponsors, schedule, FAQs, gallery ===

    public function test_sponsors_are_ordered_by_tier_then_position(): void
    {
        Sponsor::factory()->tier('bronze')->create(['name' => 'Bronze Co']);
        Sponsor::factory()->tier('platinum')->create(['name' => 'Platinum Co']);
        Sponsor::factory()->tier('gold')->create(['name' => 'Gold Co']);

        $response = $this->getJson('/api/v1/public/content/sponsors');

        $response->assertOk();
        $this->assertSame(
            ['Platinum Co', 'Gold Co', 'Bronze Co'],
            array_column($response->json('data'), 'name'),
        );
    }

    public function test_unpublished_sponsors_are_hidden(): void
    {
        Sponsor::factory()->create(['name' => 'Published Co']);
        Sponsor::factory()->unpublished()->create(['name' => 'Hidden Co']);

        $this->getJson('/api/v1/public/content/sponsors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Published Co');
    }

    public function test_schedule_is_chronological_and_hides_unpublished_items(): void
    {
        ScheduleItem::factory()->create(['title' => 'Second', 'starts_at' => now()->addDays(2)]);
        ScheduleItem::factory()->create(['title' => 'First', 'starts_at' => now()->addDay()]);
        ScheduleItem::factory()->unpublished()->create(['title' => 'Draft item', 'starts_at' => now()]);

        $response = $this->getJson('/api/v1/public/content/schedule');

        $response->assertOk();
        $this->assertSame(['First', 'Second'], array_column($response->json('data'), 'title'));
    }

    public function test_faqs_hide_unpublished_entries_and_filter_by_category(): void
    {
        Faq::factory()->create(['question' => 'Payment question?', 'category' => 'payment']);
        Faq::factory()->create(['question' => 'Venue question?', 'category' => 'venue']);
        Faq::factory()->unpublished()->create(['question' => 'Draft question?', 'category' => 'payment']);

        $this->getJson('/api/v1/public/content/faqs')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/public/content/faqs?category=payment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'Payment question?');
    }

    public function test_gallery_album_returns_only_published_items(): void
    {
        $album = GalleryAlbum::factory()->create(['slug' => 'through-the-years']);
        GalleryItem::factory()->for($album, 'album')->create(['caption' => 'Shown', 'position' => 0]);
        GalleryItem::factory()->for($album, 'album')->unpublished()->create(['caption' => 'Hidden', 'position' => 1]);

        $this->getJson('/api/v1/public/content/gallery/through-the-years')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.caption', 'Shown');
    }

    public function test_unpublished_gallery_album_returns_404(): void
    {
        GalleryAlbum::factory()->unpublished()->create(['slug' => 'private-album']);

        $this->getJson('/api/v1/public/content/gallery/private-album')->assertNotFound();
    }

    // === Response shape ===

    public function test_public_media_never_exposes_storage_internals(): void
    {
        $album = GalleryAlbum::factory()->create(['slug' => 'album']);
        GalleryItem::factory()->for($album, 'album')->create();

        $item = $this->getJson('/api/v1/public/content/gallery/album')
            ->assertOk()
            ->json('data.items.0.media');

        $this->assertSame(
            ['ulid', 'url', 'mime_type', 'width', 'height'],
            array_keys($item),
        );
    }
}
