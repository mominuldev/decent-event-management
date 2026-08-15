<?php

namespace Tests\Feature\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendeeProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendee_can_set_their_own_photo(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();
        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $response = $this->post(route('api.v1.attendee.me.photo.store'), [
            'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['ulid', 'url', 'width', 'height']]);

        $media = MediaFile::where('ulid', $response->json('data.ulid'))->firstOrFail();

        $this->assertFalse($media->is_public, 'a photograph of a person must never land on the public disk');
        $this->assertSame('local', $media->disk);
        $this->assertSame('profile_photo', $media->collection);
        Storage::disk('local')->assertExists($media->path);

        $this->assertSame($media->id, $attendee->fresh()->profile_photo_media_id);

        // Served through a signed route, never a guessable public path.
        $this->assertStringContainsString('signature=', (string) $response->json('data.url'));

        // GET /attendee/me now surfaces it too.
        $meResponse = $this->getJson(route('api.v1.attendee.me.show'));
        $meResponse->assertStatus(200);
        $this->assertStringContainsString('signature=', (string) $meResponse->json('data.profile_photo_url'));
    }

    public function test_setting_a_new_photo_replaces_and_soft_deletes_the_old_one(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();
        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $first = $this->post(route('api.v1.attendee.me.photo.store'), [
            'photo' => UploadedFile::fake()->image('first.jpg', 400, 400),
        ]);
        $firstMediaId = $first->json('data.ulid');

        $second = $this->post(route('api.v1.attendee.me.photo.store'), [
            'photo' => UploadedFile::fake()->image('second.jpg', 400, 400),
        ]);
        $second->assertStatus(200);

        $this->assertSame(
            MediaFile::where('ulid', $second->json('data.ulid'))->firstOrFail()->id,
            $attendee->fresh()->profile_photo_media_id,
        );

        $this->assertSoftDeleted('media_files', ['ulid' => $firstMediaId]);
    }

    public function test_attendee_can_remove_their_photo(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();
        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $upload = $this->post(route('api.v1.attendee.me.photo.store'), [
            'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ]);
        $mediaUlid = $upload->json('data.ulid');

        $response = $this->delete(route('api.v1.attendee.me.photo.destroy'));
        $response->assertStatus(204);

        $this->assertNull($attendee->fresh()->profile_photo_media_id);
        $this->assertSoftDeleted('media_files', ['ulid' => $mediaUlid]);
    }

    public function test_removing_a_photo_when_there_is_none_is_a_no_op(): void
    {
        $attendee = Attendee::factory()->create(['profile_photo_media_id' => null]);
        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $response = $this->delete(route('api.v1.attendee.me.photo.destroy'));

        $response->assertStatus(204);
        $this->assertNull($attendee->fresh()->profile_photo_media_id);
    }

    public function test_photo_upload_rejects_a_non_image_disguised_as_one(): void
    {
        Storage::fake('local');
        $attendee = Attendee::factory()->create();
        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $response = $this->post(route('api.v1.attendee.me.photo.store'), [
            'photo' => UploadedFile::fake()->createWithContent('me.jpg', '<?php echo "pwned";'),
        ]);

        $response->assertStatus(422)->assertJsonFragment(['code' => 'photo_rejected']);
        $this->assertNull($attendee->fresh()->profile_photo_media_id);
    }

    public function test_photo_upload_requires_authentication(): void
    {
        $response = $this->post(route('api.v1.attendee.me.photo.store'), [
            'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ]);

        $response->assertStatus(401);
    }
}
