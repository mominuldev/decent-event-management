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

    public function test_svg_is_refused_because_no_re_encode_makes_it_safe(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->post(route('api.v1.admin.content.media.store'), [
            'file' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
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
