<?php

namespace Tests\Feature\Admin;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The CMS page workspace (docs/08 Phase 3.5, admin half).
 *
 * The invariants worth guarding are the ones a future refactor could quietly
 * break: saving never publishes, publishing is separately permissioned,
 * history is append-only, and the preview token is readable exactly once.
 */
class ContentPageAdminTest extends TestCase
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

    /**
     * Someone who may write copy but not push it live — no seeded role sits
     * in that gap, so the permissions are granted directly.
     */
    private function editorWithoutPublishRights(): User
    {
        $editor = User::factory()->create(['status' => 'active']);
        $editor->givePermissionTo(['content.view_any', 'content.view', 'content.create', 'content.update']);

        return $editor;
    }

    public function test_creating_a_page_stores_a_draft_with_its_blocks_and_first_revision(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $response = $this->postJson(route('api.v1.admin.content.pages.store'), [
            'slug' => 'about-us',
            'title' => 'About us',
            'title_bn' => 'আমাদের সম্পর্কে',
            'change_note' => 'First draft',
            'blocks' => [
                ['type' => 'hero', 'data' => ['heading' => 'A Hundred Years'], 'data_bn' => ['heading' => 'একশ বছর']],
                ['type' => 'rich_text', 'data' => ['heading' => 'History', 'body' => 'Founded a century ago.']],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'about-us')
            // Creation never publishes, whatever the caller sends.
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_live', false)
            ->assertJsonPath('data.revision_number', 1)
            ->assertJsonCount(2, 'data.blocks');

        $page = ContentPage::where('slug', 'about-us')->firstOrFail();

        $this->assertSame([0, 1], $page->blocks()->pluck('position')->all());
        $this->assertDatabaseHas('content_page_revisions', [
            'content_page_id' => $page->id,
            'revision_number' => 1,
            'status_at_capture' => 'draft',
            'change_note' => 'First draft',
        ]);
    }

    public function test_saving_captures_a_new_revision_and_leaves_status_alone(): void
    {
        $page = ContentPage::factory()->published()->create(['revision_number' => 1]);
        ContentBlock::factory()->create(['content_page_id' => $page->id, 'type' => 'rich_text', 'position' => 0]);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $page->ulid]), [
            'title' => 'Reworded title',
            // A `status` field must be ignored rather than honoured — moving
            // a page through the workflow needs content.publish.
            'status' => 'archived',
            'change_note' => 'Reworded the heading',
        ])->assertStatus(200)
            ->assertJsonPath('data.title', 'Reworded title')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.revision_number', 2);

        // The factory-made page has no history of its own, so this save is
        // the only revision — captured at number 2, matching the page.
        $this->assertDatabaseHas('content_page_revisions', [
            'content_page_id' => $page->id,
            'revision_number' => 2,
            'status_at_capture' => 'published',
            'change_note' => 'Reworded the heading',
        ]);
    }

    public function test_omitting_blocks_leaves_the_existing_tree_untouched(): void
    {
        $page = ContentPage::factory()->create(['revision_number' => 1]);
        ContentBlock::factory()->create(['content_page_id' => $page->id, 'type' => 'rich_text', 'position' => 0]);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $page->ulid]), [
            'title' => 'Only the title changed',
        ])->assertStatus(200)->assertJsonCount(1, 'data.blocks');

        $this->assertSame(1, $page->blocks()->count());
    }

    public function test_sending_blocks_replaces_the_tree_and_keeps_matched_block_ulids(): void
    {
        $page = ContentPage::factory()->create(['revision_number' => 1]);
        $kept = ContentBlock::factory()->create(['content_page_id' => $page->id, 'type' => 'rich_text', 'position' => 0]);
        $dropped = ContentBlock::factory()->create(['content_page_id' => $page->id, 'type' => 'cta', 'position' => 1]);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $page->ulid]), [
            'blocks' => [
                ['type' => 'hero', 'data' => ['heading' => 'New first block']],
                ['ulid' => $kept->ulid, 'type' => 'rich_text', 'data' => ['heading' => 'Still here']],
            ],
        ])->assertStatus(200)->assertJsonCount(2, 'data.blocks');

        // Updated in place, so its public identifier survives the save.
        $this->assertDatabaseHas('content_blocks', ['id' => $kept->id, 'position' => 1]);
        $this->assertDatabaseMissing('content_blocks', ['id' => $dropped->id]);
    }

    public function test_publishing_makes_a_page_live_and_illegal_transitions_are_rejected(): void
    {
        $page = ContentPage::factory()->create();

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), ['status' => 'published'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_live', true);

        // published → in_review is not in ContentPage::TRANSITIONS.
        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), ['status' => 'in_review'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition');

        $this->assertSame('published', $page->fresh()?->status);
    }

    public function test_a_future_publish_date_schedules_the_page_out_of_the_public_api(): void
    {
        $page = ContentPage::factory()->create(['slug' => 'gala-night']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), [
            'status' => 'published',
            'published_at' => now()->addWeek()->toISOString(),
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'published')
            // Published, but scopeLive() still excludes it.
            ->assertJsonPath('data.is_live', false);

        $this->getJson('/api/v1/public/content/pages/gala-night')->assertStatus(404);
    }

    public function test_preview_token_is_returned_once_and_unlocks_a_draft_publicly(): void
    {
        $page = ContentPage::factory()->create(['slug' => 'draft-page']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $response = $this->postJson(route('api.v1.admin.content.pages.preview-token', ['page' => $page->ulid]))
            ->assertStatus(200);

        $token = $response->json('data.preview_token');
        $this->assertIsString($token);

        // A draft is a 404 without the token, and readable with it.
        $this->getJson('/api/v1/public/content/pages/draft-page')->assertStatus(404);
        $this->getJson("/api/v1/public/content/pages/draft-page?preview_token={$token}")->assertStatus(200);

        // And the secret never reappears on the editorial view.
        $show = $this->getJson(route('api.v1.admin.content.pages.show', ['page' => $page->ulid]))->assertStatus(200);
        $this->assertArrayNotHasKey('preview_token', $show->json('data'));
        $this->assertTrue($show->json('data.has_preview_token'));
    }

    public function test_rotating_the_preview_token_invalidates_the_previous_link(): void
    {
        $page = ContentPage::factory()->create(['slug' => 'rotating-page']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $first = $this->postJson(route('api.v1.admin.content.pages.preview-token', ['page' => $page->ulid]))->json('data.preview_token');
        $second = $this->postJson(route('api.v1.admin.content.pages.preview-token', ['page' => $page->ulid]))->json('data.preview_token');

        $this->assertNotSame($first, $second);
        $this->getJson("/api/v1/public/content/pages/rotating-page?preview_token={$first}")->assertStatus(404);
        $this->getJson("/api/v1/public/content/pages/rotating-page?preview_token={$second}")->assertStatus(200);
    }

    public function test_restoring_a_revision_writes_a_new_revision_and_keeps_the_status(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $created = $this->postJson(route('api.v1.admin.content.pages.store'), [
            'slug' => 'history',
            'title' => 'Original title',
            'blocks' => [['type' => 'rich_text', 'data' => ['heading' => 'Original heading']]],
        ])->assertStatus(201);

        $pageUlid = $created->json('data.ulid');
        $page = ContentPage::where('ulid', $pageUlid)->firstOrFail();

        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $pageUlid]), ['status' => 'published']);

        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $pageUlid]), [
            'title' => 'Rewritten title',
            'blocks' => [['type' => 'rich_text', 'data' => ['heading' => 'Rewritten heading']]],
        ])->assertStatus(200);

        $firstRevision = $page->revisions()->where('revision_number', 1)->firstOrFail();

        $restored = $this->postJson(route('api.v1.admin.content.pages.revisions.restore', [
            'page' => $pageUlid,
            'revision' => $firstRevision->ulid,
        ]))->assertStatus(200);

        $restored->assertJsonPath('data.title', 'Original title')
            // A restore is a save on top of the history, not a rewind…
            ->assertJsonPath('data.revision_number', 3)
            // …and it must not republish or unpublish the page.
            ->assertJsonPath('data.status', 'published');

        $this->assertSame('Original heading', $page->fresh()?->blocks()->first()?->data['heading'] ?? null);
        $this->assertSame(3, $page->revisions()->count());
    }

    public function test_a_revision_belonging_to_another_page_is_not_found(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $pageA = ContentPage::factory()->create();
        $pageB = ContentPage::factory()->create();

        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $pageB->ulid]), ['title' => 'Touched']);
        $revisionOfB = $pageB->revisions()->firstOrFail();

        $this->postJson(route('api.v1.admin.content.pages.revisions.restore', [
            'page' => $pageA->ulid,
            'revision' => $revisionOfB->ulid,
        ]))->assertStatus(404);
    }

    public function test_an_editor_without_publish_permission_cannot_change_status(): void
    {
        $page = ContentPage::factory()->create();

        Sanctum::actingAs($this->editorWithoutPublishRights(), ['admin'], 'web-admin');

        // Editing is fine…
        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $page->ulid]), ['title' => 'Edited'])
            ->assertStatus(200);

        // …publishing is not.
        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $page->ulid]), ['status' => 'published'])
            ->assertStatus(403);

        $this->assertSame('draft', $page->fresh()?->status);
    }

    public function test_deleting_a_page_requires_the_super_admin_only_delete_permission(): void
    {
        $page = ContentPage::factory()->create();

        Sanctum::actingAs($this->editorWithoutPublishRights(), ['admin'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.content.pages.destroy', ['page' => $page->ulid]))->assertStatus(403);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
        $this->deleteJson(route('api.v1.admin.content.pages.destroy', ['page' => $page->ulid]))->assertStatus(204);

        // Soft-deleted: the row and its history stay auditable.
        $this->assertSoftDeleted('content_pages', ['id' => $page->id]);
    }

    /**
     * D8 discipline (CLAUDE.md): the six Content Actions write their own
     * audit entry so a non-HTTP caller doing the same edit is not silently
     * unlogged. Every page-mutating Action gets one row here.
     */
    public function test_page_actions_write_their_own_audit_log_entry(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $created = $this->postJson(route('api.v1.admin.content.pages.store'), [
            'slug' => 'audited-page',
            'title' => 'Audited Page',
        ])->assertStatus(201);

        $ulid = $created->json('data.ulid');
        $page = ContentPage::where('ulid', $ulid)->firstOrFail();

        $this->assertDatabaseHas('activity_logs', [
            'log_name' => 'content',
            'event' => 'created',
            'subject_type' => $page->getMorphClass(),
            'subject_id' => $page->id,
            'causer_id' => $this->admin->id,
        ]);

        $this->patchJson(route('api.v1.admin.content.pages.update', ['page' => $ulid]), ['title' => 'Renamed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'log_name' => 'content',
            'event' => 'updated',
            'subject_type' => $page->getMorphClass(),
            'subject_id' => $page->id,
        ]);

        $this->postJson(route('api.v1.admin.content.pages.status', ['page' => $ulid]), ['status' => 'published'])
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'log_name' => 'content',
            'event' => 'status_changed',
            'subject_type' => $page->getMorphClass(),
            'subject_id' => $page->id,
        ]);

        $this->postJson(route('api.v1.admin.content.pages.preview-token', ['page' => $ulid]))
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'log_name' => 'content',
            'event' => 'preview_token_issued',
            'subject_type' => $page->getMorphClass(),
            'subject_id' => $page->id,
        ]);

        $this->deleteJson(route('api.v1.admin.content.pages.destroy', ['page' => $ulid]))
            ->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'log_name' => 'content',
            'event' => 'deleted',
            'subject_type' => $page->getMorphClass(),
            'subject_id' => $page->id,
        ]);
    }

    public function test_slug_must_be_unique_and_kebab_case(): void
    {
        ContentPage::factory()->create(['slug' => 'taken']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->postJson(route('api.v1.admin.content.pages.store'), ['slug' => 'taken', 'title' => 'Duplicate'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->postJson(route('api.v1.admin.content.pages.store'), ['slug' => 'Not A Slug', 'title' => 'Bad slug'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_an_unknown_block_type_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->postJson(route('api.v1.admin.content.pages.store'), [
            'slug' => 'bad-block',
            'title' => 'Bad block',
            'blocks' => [['type' => 'arbitrary_html', 'data' => ['body' => '<script>']]],
        ])->assertStatus(422)->assertJsonValidationErrors('blocks.0.type');
    }

    public function test_the_page_list_shows_every_status_unlike_the_public_endpoint(): void
    {
        ContentPage::factory()->create(['slug' => 'a-draft']);
        ContentPage::factory()->archived()->create(['slug' => 'an-archive']);
        ContentPage::factory()->published()->create(['slug' => 'a-live-one']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->getJson(route('api.v1.admin.content.pages.index'))
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');

        $this->getJson(route('api.v1.admin.content.pages.index', ['status' => 'archived']))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'an-archive');
    }
}
