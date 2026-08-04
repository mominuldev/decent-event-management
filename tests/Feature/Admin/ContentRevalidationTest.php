<?php

namespace Tests\Feature\Admin;

use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\Sponsor;
use App\Domain\Shared\Models\User;
use App\Jobs\RevalidateFrontendContentJob;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The ISR revalidation hook (docs/08 Phase 3.5).
 *
 * Content publishes a `ContentChanged` event and knows nothing about a
 * Next.js site; only the listener does, and only when the frontend has told
 * us where to ping. The interesting cases are the quiet ones — editing a
 * draft changes nothing public, and an unconfigured hook must be a clean
 * no-op rather than a failed job on every save.
 */
class ContentRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('Super Admin');

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
    }

    private function configureHook(): void
    {
        config(['services.frontend.revalidate_url' => 'https://site.example.com/api/revalidate']);
    }

    public function test_publishing_a_page_queues_a_revalidation_for_its_slug(): void
    {
        $this->configureHook();

        $page = ContentPage::factory()->create(['slug' => 'about-us']);

        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), ['status' => 'published'])
            ->assertStatus(200);

        Queue::assertPushed(
            RevalidateFrontendContentJob::class,
            fn (RevalidateFrontendContentJob $job): bool => $job->slug === 'about-us' && $job->reason === 'page.published',
        );
    }

    public function test_editing_a_live_page_queues_a_revalidation_but_editing_a_draft_does_not(): void
    {
        $this->configureHook();

        $draft = ContentPage::factory()->create();
        $live = ContentPage::factory()->published()->create(['slug' => 'live-page']);

        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $draft->ulid]), ['title' => 'Still hidden']);

        // Nothing the public can see has changed yet.
        Queue::assertNotPushed(RevalidateFrontendContentJob::class);

        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $live->ulid]), ['title' => 'Changed in public']);

        Queue::assertPushed(
            RevalidateFrontendContentJob::class,
            fn (RevalidateFrontendContentJob $job): bool => $job->slug === 'live-page' && $job->reason === 'page.updated',
        );
    }

    public function test_unpublishing_still_revalidates_so_the_cdn_drops_the_old_copy(): void
    {
        $this->configureHook();

        $page = ContentPage::factory()->published()->create(['slug' => 'pulled-page']);

        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), ['status' => 'draft'])
            ->assertStatus(200);

        Queue::assertPushed(
            RevalidateFrontendContentJob::class,
            fn (RevalidateFrontendContentJob $job): bool => $job->slug === 'pulled-page' && $job->reason === 'page.draft',
        );
    }

    public function test_a_shared_collection_revalidates_the_whole_site(): void
    {
        $this->configureHook();

        Sponsor::factory()->create();

        $this->postJson(route('api.v1.admin.content.sponsors.store'), ['name' => 'New Sponsor'])->assertStatus(201);

        // No slug: any page may render the sponsor grid.
        Queue::assertPushed(
            RevalidateFrontendContentJob::class,
            fn (RevalidateFrontendContentJob $job): bool => $job->slug === null && $job->reason === 'sponsor.created',
        );
    }

    public function test_nothing_is_queued_while_the_hook_is_unconfigured(): void
    {
        config(['services.frontend.revalidate_url' => null]);

        $page = ContentPage::factory()->create();

        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), ['status' => 'published'])
            ->assertStatus(200);

        Queue::assertNotPushed(RevalidateFrontendContentJob::class);
    }
}
