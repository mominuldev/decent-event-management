<?php

namespace Tests\Feature\Media;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\GenerateMediaThumbnail;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaThumbnailTest extends TestCase
{
    use RefreshDatabase;

    /** A profile photo the size a real upload lands at — well past the budget. */
    private function uploadPhoto(Attendee $attendee, string $name = 'me.jpg'): MediaFile
    {
        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $response = $this->post(route('api.v1.attendee.me.photo.store'), [
            'photo' => UploadedFile::fake()->image($name, 900, 600),
        ]);

        $response->assertStatus(200);

        return MediaFile::where('ulid', $response->json('data.ulid'))->firstOrFail();
    }

    public function test_uploading_a_profile_photo_derives_a_linked_thumbnail(): void
    {
        Storage::fake('local');

        $media = $this->uploadPhoto(Attendee::factory()->create());
        $thumbnail = $media->thumbnail;

        $this->assertNotNull($thumbnail, 'an upload past the size budget must derive a thumbnail');
        $this->assertSame('thumbnail', $thumbnail->collection);
        Storage::disk('local')->assertExists($thumbnail->path);

        // Fits the budget, and keeps the original's aspect ratio (3:2 here).
        $this->assertSame(GenerateMediaThumbnail::MAX_DIMENSION, (int) $thumbnail->width);
        $this->assertSame(85, (int) $thumbnail->height);

        // The whole point: a list must not pull the full-size original.
        $this->assertLessThan(
            (int) $media->size_bytes,
            (int) $thumbnail->size_bytes,
            'the derivative must be smaller than the photo it stands in for',
        );

        $this->assertSame('image/webp', $thumbnail->mime_type);
    }

    public function test_a_thumbnail_of_a_private_photo_is_never_public(): void
    {
        Storage::fake('local');

        $thumbnail = $this->uploadPhoto(Attendee::factory()->create())->thumbnail;

        $this->assertNotNull($thumbnail);
        $this->assertFalse($thumbnail->is_public, 'a small copy of a private photograph is still private');
        $this->assertSame('local', $thumbnail->disk);
    }

    public function test_an_image_already_within_the_budget_gets_no_derivative(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();
        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $response = $this->post(route('api.v1.attendee.me.photo.store'), [
            'photo' => UploadedFile::fake()->image('tiny.jpg', 64, 64),
        ]);

        $media = MediaFile::where('ulid', $response->json('data.ulid'))->firstOrFail();

        $this->assertNull($media->thumbnail_media_id, 'a second copy of the same bytes is not a thumbnail');
        // The caller still gets a usable image rather than nothing.
        $this->assertTrue($media->smallest()->is($media));
    }

    public function test_replacing_a_photo_soft_deletes_the_old_thumbnail_with_it(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();

        $first = $this->uploadPhoto($attendee, 'first.jpg');
        $firstThumbnail = $first->thumbnail;
        $this->assertNotNull($firstThumbnail);

        $second = $this->uploadPhoto($attendee, 'second.jpg');

        $this->assertSame($second->id, $attendee->fresh()->profile_photo_media_id);
        $this->assertSoftDeleted('media_files', ['id' => $first->id]);
        // Orphaned by the same move, and just as servable if left behind.
        $this->assertSoftDeleted('media_files', ['id' => $firstThumbnail->id]);
        $this->assertNotNull($second->thumbnail);
    }

    public function test_removing_a_photo_soft_deletes_its_thumbnail_with_it(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();

        $media = $this->uploadPhoto($attendee);
        $thumbnail = $media->thumbnail;
        $this->assertNotNull($thumbnail);

        $this->delete(route('api.v1.attendee.me.photo.destroy'))->assertStatus(204);

        $this->assertSoftDeleted('media_files', ['id' => $media->id]);
        $this->assertSoftDeleted('media_files', ['id' => $thumbnail->id]);
    }

    public function test_the_attendee_resource_points_avatars_at_the_thumbnail(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();
        $media = $this->uploadPhoto($attendee);

        $response = $this->getJson(route('api.v1.attendee.me.show'));
        $response->assertStatus(200);

        $thumbUrl = (string) $response->json('data.profile_photo_thumb_url');
        $fullUrl = (string) $response->json('data.profile_photo_url');

        $this->assertStringContainsString((string) $media->thumbnail?->ulid, $thumbUrl);
        $this->assertStringContainsString('signature=', $thumbUrl);

        // Both are exposed: the PDF/detail paths still want the full-size one.
        $this->assertStringContainsString((string) $media->ulid, $fullUrl);
        $this->assertNotSame($thumbUrl, $fullUrl);
    }

    public function test_the_thumb_url_falls_back_to_the_photo_when_none_was_derived(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();
        $media = $this->uploadPhoto($attendee);

        // A photo stored before thumbnails existed, not yet backfilled.
        $media->thumbnail?->forceDelete();
        $media->forceFill(['thumbnail_media_id' => null])->save();

        $response = $this->getJson(route('api.v1.attendee.me.show'));

        $response->assertStatus(200);
        $this->assertStringContainsString((string) $media->ulid, (string) $response->json('data.profile_photo_thumb_url'));
    }

    public function test_the_admin_list_exposes_a_thumb_url_for_every_attendee(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();
        $media = $this->uploadPhoto($attendee);

        $this->seed(RbacSeeder::class);

        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        Sanctum::actingAs($admin, ['admin'], 'web-admin');

        $response = $this->getJson(route('api.v1.admin.attendees.index'));

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('ulid', $attendee->ulid);

        $this->assertNotNull($row);
        $this->assertStringContainsString((string) $media->thumbnail?->ulid, (string) $row['profile_photo_thumb_url']);
    }

    public function test_the_backfill_command_derives_thumbnails_and_is_safe_to_rerun(): void
    {
        Storage::fake('local');
        $media = $this->uploadPhoto(Attendee::factory()->create());

        // Reduce it to the pre-thumbnail state a real legacy row is in.
        $media->thumbnail?->forceDelete();
        $media->forceFill(['thumbnail_media_id' => null])->save();

        $this->artisan('media:backfill-thumbnails')
            ->expectsOutputToContain('Generated 1, skipped 0, failed 0.')
            ->assertExitCode(0);

        $regenerated = $media->fresh()?->thumbnail;
        $this->assertNotNull($regenerated);
        Storage::disk('local')->assertExists($regenerated->path);

        // A second pass must find nothing rather than produce a duplicate.
        $this->artisan('media:backfill-thumbnails')
            ->expectsOutputToContain('Nothing to do')
            ->assertExitCode(0);

        $this->assertSame(1, MediaFile::where('collection', 'thumbnail')->count());
    }

    public function test_the_backfill_command_reports_a_row_whose_file_is_gone_instead_of_crashing(): void
    {
        Storage::fake('local');
        $media = $this->uploadPhoto(Attendee::factory()->create());

        $media->thumbnail?->forceDelete();
        $media->forceFill(['thumbnail_media_id' => null])->save();

        // The row survives a lost file — a restored database pointed at an
        // un-restored disk is exactly how this happens in practice.
        Storage::disk('local')->delete($media->path);

        $this->artisan('media:backfill-thumbnails')
            ->expectsOutputToContain('Generated 0, skipped 1, failed 0.')
            ->assertExitCode(0);

        $this->assertNull($media->fresh()?->thumbnail_media_id);
    }

    public function test_the_backfill_dry_run_writes_nothing(): void
    {
        Storage::fake('local');
        $media = $this->uploadPhoto(Attendee::factory()->create());

        $media->thumbnail?->forceDelete();
        $media->forceFill(['thumbnail_media_id' => null])->save();

        $this->artisan('media:backfill-thumbnails', ['--dry-run' => true])
            ->expectsOutputToContain('Nothing was written.')
            ->assertExitCode(0);

        $this->assertNull($media->fresh()?->thumbnail_media_id);
        $this->assertSame(0, MediaFile::where('collection', 'thumbnail')->count());
    }
}
