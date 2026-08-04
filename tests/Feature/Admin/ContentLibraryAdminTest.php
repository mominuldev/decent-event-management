<?php

namespace Tests\Feature\Admin;

use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\Faq;
use App\Domain\Content\Models\GalleryAlbum;
use App\Domain\Content\Models\GalleryItem;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;
use App\Domain\Content\Models\ScheduleItem;
use App\Domain\Content\Models\Sponsor;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The simple CMS collections — sponsors, schedule, FAQs, gallery, menus.
 *
 * They share one Action (SaveContentResource), so the tests concentrate on
 * what differs per collection: ordering rules, the soft session reference,
 * album/menu scoping, and the audit entry the Action is there to guarantee.
 */
class ContentLibraryAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('Super Admin');

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
    }

    private function editor(): User
    {
        $editor = User::factory()->create(['status' => 'active']);
        $editor->givePermissionTo(['content.view_any', 'content.view', 'content.create', 'content.update']);

        return $editor;
    }

    /* ------------------------------------------------------------ Sponsors */

    public function test_sponsors_can_be_created_and_are_listed_in_tier_order(): void
    {
        Sponsor::factory()->create(['name' => 'Bronze Co', 'tier' => 'bronze', 'position' => 0]);
        Sponsor::factory()->create(['name' => 'Platinum Co', 'tier' => 'platinum', 'position' => 0]);

        $this->postJson(route('api.v1.admin.content.sponsors.store'), [
            'name' => 'Gold Co',
            'name_bn' => 'গোল্ড কোং',
            'tier' => 'gold',
            'website_url' => 'https://gold.example.com',
            'is_published' => true,
        ])->assertStatus(201)->assertJsonPath('data.tier', 'gold');

        // Tier order comes from Sponsor::TIERS, not from the string's spelling.
        $this->getJson(route('api.v1.admin.content.sponsors.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Platinum Co')
            ->assertJsonPath('data.1.name', 'Gold Co')
            ->assertJsonPath('data.2.name', 'Bronze Co');
    }

    public function test_saving_a_sponsor_writes_an_audit_entry_from_the_action(): void
    {
        $response = $this->postJson(route('api.v1.admin.content.sponsors.store'), ['name' => 'Audited Co'])
            ->assertStatus(201);

        $sponsor = Sponsor::where('ulid', $response->json('data.ulid'))->firstOrFail();

        $this->assertDatabaseHas('activity_logs', [
            'log_name' => 'content',
            'event' => 'created',
            'subject_type' => $sponsor->getMorphClass(),
            'subject_id' => $sponsor->id,
            'causer_id' => $this->admin->id,
        ]);
    }

    public function test_an_invalid_sponsor_tier_is_rejected(): void
    {
        $this->postJson(route('api.v1.admin.content.sponsors.store'), ['name' => 'Nope', 'tier' => 'diamond'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tier');
    }

    /* ------------------------------------------------------------ Schedule */

    public function test_schedule_items_accept_a_session_code_that_matches_no_session(): void
    {
        // The reference is deliberately soft (ScheduleItem's class note):
        // published copy must survive a session being renamed or removed.
        $this->postJson(route('api.v1.admin.content.schedule.store'), [
            'title' => 'Opening ceremony',
            'starts_at' => now()->addMonth()->toISOString(),
            'event_session_code' => 'session-that-does-not-exist',
        ])->assertStatus(201)->assertJsonPath('data.event_session_code', 'session-that-does-not-exist');
    }

    public function test_a_schedule_item_cannot_end_before_it_starts(): void
    {
        $this->postJson(route('api.v1.admin.content.schedule.store'), [
            'title' => 'Time travel',
            'starts_at' => now()->addMonth()->toISOString(),
            'ends_at' => now()->addMonth()->subHour()->toISOString(),
        ])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_schedule_items_are_listed_chronologically(): void
    {
        ScheduleItem::factory()->create(['title' => 'Later', 'starts_at' => now()->addDays(2)]);
        ScheduleItem::factory()->create(['title' => 'Earlier', 'starts_at' => now()->addDay()]);

        $this->getJson(route('api.v1.admin.content.schedule.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Earlier')
            ->assertJsonPath('data.1.title', 'Later');
    }

    /* ---------------------------------------------------------------- FAQs */

    public function test_faqs_can_be_created_updated_and_deleted(): void
    {
        $created = $this->postJson(route('api.v1.admin.content.faqs.store'), [
            'question' => 'Where is the venue?',
            'question_bn' => 'অনুষ্ঠানস্থল কোথায়?',
            'answer' => 'On the main campus.',
            'category' => 'logistics',
            'is_published' => true,
        ])->assertStatus(201);

        $ulid = $created->json('data.ulid');

        $this->patchJson(route('api.v1.admin.content.faqs.update', ['faq' => $ulid]), ['answer' => 'On the north campus.'])
            ->assertStatus(200)
            ->assertJsonPath('data.answer', 'On the north campus.');

        $this->deleteJson(route('api.v1.admin.content.faqs.destroy', ['faq' => $ulid]))->assertStatus(204);

        $this->assertDatabaseMissing('faqs', ['ulid' => $ulid]);
    }

    public function test_an_editor_without_delete_permission_cannot_delete_an_faq(): void
    {
        $faq = Faq::factory()->create();

        Sanctum::actingAs($this->editor(), ['admin'], 'web-admin');

        $this->deleteJson(route('api.v1.admin.content.faqs.destroy', ['faq' => $faq->ulid]))->assertStatus(403);
        $this->assertDatabaseHas('faqs', ['id' => $faq->id]);
    }

    /* ------------------------------------------------------------- Gallery */

    public function test_pictures_are_added_to_an_album_and_scoped_to_it(): void
    {
        $album = GalleryAlbum::factory()->create();
        $otherAlbum = GalleryAlbum::factory()->create();
        $media = MediaFile::factory()->create(['collection' => 'gallery', 'is_public' => true]);

        $created = $this->postJson(route('api.v1.admin.content.gallery.items.store', ['album' => $album->ulid]), [
            'media_ulid' => $media->ulid,
            'caption' => 'Opening night',
        ])->assertStatus(201);

        $itemUlid = $created->json('data.ulid');

        // Same item, wrong album — a 404, not a silent cross-album edit.
        $this->patchJson(route('api.v1.admin.content.gallery.items.update', [
            'album' => $otherAlbum->ulid,
            'item' => $itemUlid,
        ]), ['caption' => 'Hijacked'])->assertStatus(404);

        $this->assertDatabaseHas('gallery_items', ['ulid' => $itemUlid, 'caption' => 'Opening night']);
    }

    public function test_an_album_shows_every_item_including_unpublished_ones(): void
    {
        $album = GalleryAlbum::factory()->create();
        GalleryItem::factory()->create(['gallery_album_id' => $album->id, 'is_published' => true]);
        GalleryItem::factory()->create(['gallery_album_id' => $album->id, 'is_published' => false]);

        $this->getJson(route('api.v1.admin.content.gallery.show', ['album' => $album->ulid]))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_a_gallery_item_requires_a_media_file(): void
    {
        $album = GalleryAlbum::factory()->create();

        $this->postJson(route('api.v1.admin.content.gallery.items.store', ['album' => $album->ulid]), ['caption' => 'No picture'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('media_ulid');
    }

    /* --------------------------------------------------------------- Menus */

    public function test_a_menu_item_may_link_to_a_page_or_a_url_but_not_both(): void
    {
        $menu = Menu::factory()->create();
        $page = ContentPage::factory()->published()->create();

        $this->postJson(route('api.v1.admin.content.menus.items.store', ['menu' => $menu->ulid]), [
            'label' => 'Both at once',
            'page_ulid' => $page->ulid,
            'url' => 'https://example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('page_ulid');

        $this->postJson(route('api.v1.admin.content.menus.items.store', ['menu' => $menu->ulid]), [
            'label' => 'About',
            'page_ulid' => $page->ulid,
        ])->assertStatus(201)->assertJsonPath('data.resolved_url', '/'.$page->slug);
    }

    public function test_a_menu_item_pointing_at_an_unpublished_page_resolves_to_nothing(): void
    {
        $menu = Menu::factory()->create();
        $draft = ContentPage::factory()->create();

        // The public site drops such an item rather than linking into a 404,
        // so the editor needs to see the null here.
        $this->postJson(route('api.v1.admin.content.menus.items.store', ['menu' => $menu->ulid]), [
            'label' => 'Coming soon',
            'page_ulid' => $draft->ulid,
        ])->assertStatus(201)->assertJsonPath('data.resolved_url', null);
    }

    public function test_menus_are_returned_with_a_nested_item_tree(): void
    {
        $menu = Menu::factory()->create();
        $parent = MenuItem::factory()->create(['menu_id' => $menu->id, 'parent_id' => null, 'position' => 0]);
        MenuItem::factory()->create(['menu_id' => $menu->id, 'parent_id' => $parent->id, 'position' => 0]);

        $this->getJson(route('api.v1.admin.content.menus.index'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.0.items')
            ->assertJsonCount(1, 'data.0.items.0.children');
    }

    public function test_a_menu_item_in_another_menu_is_not_found(): void
    {
        $menu = Menu::factory()->create();
        $otherMenu = Menu::factory()->create();
        $item = MenuItem::factory()->create(['menu_id' => $menu->id, 'parent_id' => null]);

        $this->deleteJson(route('api.v1.admin.content.menus.items.destroy', [
            'menu' => $otherMenu->ulid,
            'item' => $item->ulid,
        ]))->assertStatus(404);

        $this->assertDatabaseHas('menu_items', ['id' => $item->id]);
    }

    public function test_a_menu_code_must_be_unique(): void
    {
        Menu::factory()->create(['code' => 'primary']);

        $this->postJson(route('api.v1.admin.content.menus.store'), ['code' => 'primary', 'name' => 'Duplicate'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }
}
