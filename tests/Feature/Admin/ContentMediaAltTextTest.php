<?php

namespace Tests\Feature\Admin;

use App\Domain\Content\Models\Sponsor;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alt text on a media-library image: written from the admin console, served
 * localised on every embedded media object the public API returns.
 */
class ContentMediaAltTextTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('Super Admin');
    }

    public function test_alt_text_is_saved_and_returned_in_both_languages(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
        $media = MediaFile::factory()->create(['collection' => 'content', 'is_public' => true]);

        $this->patchJson(route('api.v1.admin.content.media.update', $media), [
            'alt_text' => 'Students on the front lawn, 1926',
            'alt_text_bn' => 'সামনের মাঠে শিক্ষার্থীরা, ১৯২৬',
        ])
            ->assertOk()
            ->assertJsonPath('data.alt_text', 'Students on the front lawn, 1926')
            ->assertJsonPath('data.alt_text_bn', 'সামনের মাঠে শিক্ষার্থীরা, ১৯২৬');

        $this->assertDatabaseHas('media_files', [
            'id' => $media->id,
            'alt_text' => 'Students on the front lawn, 1926',
        ]);

        // The library listing carries it too, so the editor sees what is set.
        $this->getJson(route('api.v1.admin.content.media.index'))
            ->assertOk()
            ->assertJsonPath('data.0.alt_text', 'Students on the front lawn, 1926');
    }

    public function test_a_blank_alt_text_clears_it_rather_than_storing_an_empty_string(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
        $media = MediaFile::factory()->create(['collection' => 'content', 'alt_text' => 'Old description']);

        $this->patchJson(route('api.v1.admin.content.media.update', $media), ['alt_text' => '   '])
            ->assertOk()
            ->assertJsonPath('data.alt_text', null);

        $this->assertNull($media->refresh()->alt_text);
    }

    public function test_the_edit_is_audited_from_the_action(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
        $media = MediaFile::factory()->create(['collection' => 'gallery']);

        $this->patchJson(route('api.v1.admin.content.media.update', $media), ['alt_text' => 'Described'])->assertOk();

        $log = ActivityLog::query()->where('event', 'updated')->where('subject_id', $media->id)->firstOrFail();
        $this->assertSame('Updated media '.$media->id, $log->description);
        $this->assertSame('Described', $log->properties['new']['alt_text'] ?? null);
    }

    public function test_alt_text_is_refused_past_the_column_width(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
        $media = MediaFile::factory()->create(['collection' => 'content']);

        $this->patchJson(route('api.v1.admin.content.media.update', $media), ['alt_text' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alt_text');
    }

    public function test_a_non_cms_file_cannot_be_described_through_the_content_api(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
        $proof = MediaFile::factory()->create(['collection' => 'payment_proof', 'is_public' => false]);

        $this->patchJson(route('api.v1.admin.content.media.update', $proof), ['alt_text' => 'x'])
            ->assertNotFound();
    }

    public function test_it_needs_the_manage_media_permission(): void
    {
        $viewer = User::factory()->create(['status' => 'active']);
        $viewer->assignRole('Volunteer');
        Sanctum::actingAs($viewer, ['admin'], 'web-admin');
        $media = MediaFile::factory()->create(['collection' => 'content']);

        $this->patchJson(route('api.v1.admin.content.media.update', $media), ['alt_text' => 'x'])
            ->assertForbidden();
    }

    public function test_the_public_api_serves_the_alt_localised_with_an_english_fallback(): void
    {
        $logo = MediaFile::factory()->create([
            'collection' => 'sponsor_logo',
            'is_public' => true,
            'alt_text' => 'Acme Bank logo',
            'alt_text_bn' => 'অ্যাকমি ব্যাংকের লোগো',
        ]);
        $bare = MediaFile::factory()->create([
            'collection' => 'sponsor_logo',
            'is_public' => true,
            'alt_text' => 'Only English',
            'alt_text_bn' => null,
        ]);
        $acme = Sponsor::factory()->create(['name' => 'Acme Bank', 'logo_media_id' => $logo->id, 'position' => 1]);
        $bareCo = Sponsor::factory()->create(['name' => 'Bare Co', 'logo_media_id' => $bare->id, 'position' => 2]);

        $english = $this->getJson(route('api.v1.public.content.sponsors.index'))->assertOk()->json('data');
        $bangla = $this->getJson(route('api.v1.public.content.sponsors.index').'?locale=bn')->assertOk()->json('data');

        // The sponsor's own name is localised too, so match on the ULID.
        $logoOf = fn (array $rows, Sponsor $sponsor): array => collect($rows)->firstWhere('ulid', $sponsor->ulid)['logo'];

        $this->assertSame('Acme Bank logo', $logoOf($english, $acme)['alt']);
        $this->assertSame('অ্যাকমি ব্যাংকের লোগো', $logoOf($bangla, $acme)['alt']);
        $this->assertSame('Only English', $logoOf($bangla, $bareCo)['alt']);
    }
}
