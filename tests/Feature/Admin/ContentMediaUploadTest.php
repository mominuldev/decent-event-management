<?php

namespace Tests\Feature\Admin;

use App\Domain\Content\Models\GalleryAlbum;
use App\Domain\Content\Models\GalleryItem;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The media library upload path — also the endpoint D9 flagged as missing.
 *
 * The rules under test are the ones in CLAUDE.md / docs/06 §6.5: type comes
 * from magic bytes, never the extension or the client's Content-Type; the
 * image is re-encoded rather than stored as sent; the stored name is random.
 */
class ContentMediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('Super Admin');
    }

    public function test_an_image_is_stored_re_encoded_under_a_random_name(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $response = $this->post(route('api.v1.admin.content.media.store'), [
            'file' => UploadedFile::fake()->image('holiday snap.jpg', 400, 300),
            'collection' => 'gallery',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.collection', 'gallery')
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonPath('data.width', 400)
            ->assertJsonPath('data.height', 300)
            // CMS media is public by design — it is served straight off the
            // CDN to an anonymous site, so it has a plain URL.
            ->assertJsonPath('data.is_public', true);

        $media = MediaFile::latest('id')->firstOrFail();

        $this->assertSame('public', $media->disk);
        // Randomised, and never the uploaded filename.
        $this->assertMatchesRegularExpression('#^content/[0-9a-z]{26}\.jpg$#', $media->path);
        $this->assertStringNotContainsString('holiday', $media->path);
        Storage::disk('public')->assertExists($media->path);

        // Re-encoded: the checksum is of our bytes, not the uploader's.
        $this->assertSame(hash('sha256', (string) Storage::disk('public')->get($media->path)), $media->checksum_sha256);
    }

    public function test_a_non_image_wearing_an_image_extension_is_refused(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        // Named .jpg and declared image/jpeg, but the bytes are not an image.
        $this->post(route('api.v1.admin.content.media.store'), [
            'file' => UploadedFile::fake()->createWithContent('payload.jpg', '<?php echo "hi";'),
        ])->assertStatus(422);

        $this->assertSame(0, MediaFile::count());
    }

    public function test_an_svg_is_stored_rebuilt_without_its_script(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        // A logo the way a design tool exports it, with everything an
        // attacker might add: a script, an event handler, a javascript: link,
        // foreign HTML, and a reference to another origin.
        $svg = <<<'SVG'
            <?xml version="1.0" encoding="utf-8"?>
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 619.69 151.31">
              <defs><linearGradient id="g"><stop offset="0" stop-color="#1b4ef5"/><stop offset="1" stop-color="#f6850c"/></linearGradient></defs>
              <script>alert(1)</script>
              <path fill="url(#g)" onclick="alert(2)" d="M0 0h100v100H0z"/>
              <a xlink:href="javascript:alert(3)"><circle cx="5" cy="5" r="2" fill="#ed2224"/></a>
              <use xlink:href="#g"/><use xlink:href="https://evil.example/x.svg#p"/>
              <image xlink:href="https://evil.example/track.png"/>
              <foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="https://evil.example"/></body></foreignObject>
              <text style="fill:#204391;background:url(https://evil.example/a.png)" font-family="Inter">১৯২৭</text>
            </svg>
            SVG;

        $response = $this->post(route('api.v1.admin.content.media.store'), [
            'file' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.mime_type', 'image/svg+xml')
            // Intrinsic size comes from the viewBox when width/height are absent.
            ->assertJsonPath('data.width', 620)
            ->assertJsonPath('data.height', 151);

        $media = MediaFile::latest('id')->firstOrFail();
        $this->assertMatchesRegularExpression('#^content/[0-9a-z]{26}\.svg$#', $media->path);

        $stored = (string) Storage::disk('public')->get($media->path);

        // What a logo needs survives…
        $this->assertStringContainsString('<path fill="url(#g)" d="M0 0h100v100H0z"/>', $stored);
        $this->assertStringContainsString('<linearGradient id="g">', $stored);
        $this->assertStringContainsString('<use xlink:href="#g"/>', $stored);
        $this->assertStringContainsString('font-family="Inter">১৯২৭</text>', $stored);

        // …and nothing that can run, fetch or embed does.
        foreach (['<script', 'alert(', 'onclick', 'javascript:', 'evil.example', '<a ', '<image', '<foreignObject', '<iframe', 'background:url'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, "Sanitised SVG still contains {$needle}");
        }
    }

    public function test_an_svg_declaring_entities_is_refused(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';

        $this->post(route('api.v1.admin.content.media.store'), [
            'file' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
        ])->assertStatus(422);

        $this->assertSame(0, MediaFile::count());
    }

    public function test_an_xml_file_that_is_not_an_svg_is_refused(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->post(route('api.v1.admin.content.media.store'), [
            'file' => UploadedFile::fake()->createWithContent('logo.svg', '<html xmlns="http://www.w3.org/1999/xhtml"><script>1</script></html>'),
        ])->assertStatus(422);

        $this->assertSame(0, MediaFile::count());
    }

    public function test_uploading_requires_the_manage_media_permission(): void
    {
        $viewer = User::factory()->create(['status' => 'active']);
        $viewer->givePermissionTo(['content.view_any', 'content.view', 'content.update']);

        Sanctum::actingAs($viewer, ['admin'], 'web-admin');

        $this->post(route('api.v1.admin.content.media.store'), [
            'file' => UploadedFile::fake()->image('nope.png'),
        ])->assertStatus(403);
    }

    public function test_the_library_lists_only_cms_collections(): void
    {
        MediaFile::factory()->create(['collection' => 'content']);
        // A payment proof lives in the same table but must never surface in
        // the CMS media browser.
        MediaFile::factory()->create(['collection' => 'payment_proof']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->getJson(route('api.v1.admin.content.media.index'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.collection', 'content');
    }

    public function test_media_still_used_by_a_gallery_item_cannot_be_deleted(): void
    {
        $media = MediaFile::factory()->create(['collection' => 'gallery']);
        $album = GalleryAlbum::factory()->create();
        GalleryItem::factory()->create(['gallery_album_id' => $album->id, 'media_id' => $media->id]);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->deleteJson(route('api.v1.admin.content.media.destroy', ['media' => $media->ulid]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'deletion_prevented');

        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'deleted_at' => null]);
    }

    public function test_a_non_cms_media_file_is_not_deletable_through_the_content_api(): void
    {
        $proof = MediaFile::factory()->create(['collection' => 'payment_proof']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->deleteJson(route('api.v1.admin.content.media.destroy', ['media' => $proof->ulid]))
            ->assertStatus(404);
    }

    public function test_an_unused_cms_image_is_soft_deleted(): void
    {
        $media = MediaFile::factory()->create(['collection' => 'content']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->deleteJson(route('api.v1.admin.content.media.destroy', ['media' => $media->ulid]))
            ->assertStatus(204);

        $this->assertSoftDeleted('media_files', ['id' => $media->id]);
    }
}
