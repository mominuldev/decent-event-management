<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Events\ContentChanged;
use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\ContentPageRevision;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a page and its block tree, capturing an append-only
 * revision on every save (docs/08 Phase 3.5 — "content_pages rows are
 * versioned, never overwritten in place").
 *
 * Saving never changes `status`: moving a page between draft, review,
 * published and archived is {@see ChangeContentPageStatus}'s job, so an
 * editor typing into a live page cannot accidentally unpublish it.
 */
class SaveContentPage
{
    /**
     * The page columns worth diffing into the audit trail — the editorial
     * body, not the bookkeeping fields the action sets itself.
     *
     * @var list<string>
     */
    private const array TRACKED = [
        'slug', 'template', 'title', 'title_bn', 'excerpt', 'excerpt_bn',
        'seo_title', 'seo_title_bn', 'seo_description', 'seo_description_bn',
        'og_image_media_id', 'is_indexable', 'position',
    ];

    /**
     * @param  ContentPage|null  $page  null creates a new draft
     * @param  array<string, mixed>  $attributes  validated page fields; an optional
     *                                            `blocks` key replaces the whole block tree
     */
    public function execute(
        ?ContentPage $page,
        array $attributes,
        User $editor,
        ?string $changeNote = null,
        ?string $ip = null,
        ?string $requestId = null,
    ): ContentPage {
        return DB::transaction(function () use ($page, $attributes, $editor, $changeNote, $ip, $requestId): ContentPage {
            /** @var list<array<string, mixed>>|null $blocks */
            $blocks = null;

            if (isset($attributes['blocks']) && is_array($attributes['blocks'])) {
                $submitted = $attributes['blocks'];

                // FormRequest::validated() rebuilds nested data rule by rule,
                // so its array *keys* are right but its iteration order is
                // not — an entry carrying an optional key can come back
                // first. Sort by key before reindexing, or block order (and
                // therefore the page's layout) silently scrambles on save.
                ksort($submitted, SORT_NUMERIC);

                $blocks = array_values($submitted);
            }

            unset($attributes['blocks'], $attributes['change_note'], $attributes['status']);

            $attributes = $this->resolveOgImage($attributes);

            $isNew = $page === null;
            $before = $isNew ? null : $page->only(self::TRACKED);

            if ($page === null) {
                $page = ContentPage::create(array_merge($attributes, [
                    'status' => 'draft',
                    'created_by_user_id' => $editor->id,
                    'updated_by_user_id' => $editor->id,
                    'revision_number' => 1,
                ]));
            } else {
                $page->fill(array_merge($attributes, [
                    'updated_by_user_id' => $editor->id,
                    'revision_number' => (int) $page->revision_number + 1,
                ]));
                $page->save();
            }

            if ($blocks !== null) {
                $this->syncBlocks($page, $blocks);
            }

            $this->captureRevision($page, $editor, $changeNote);

            ActivityLog::create([
                'log_name' => 'content',
                'event' => $isNew ? 'created' : 'updated',
                'description' => ($isNew ? 'Created' : 'Updated')." content page {$page->slug}",
                'causer_type' => $editor->getMorphClass(),
                'causer_id' => $editor->id,
                'subject_type' => $page->getMorphClass(),
                'subject_id' => $page->id,
                'properties' => [
                    'old' => $before,
                    'new' => $page->only(self::TRACKED),
                    'revision_number' => $page->revision_number,
                    'change_note' => $changeNote,
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            // Editing a page that is already live changes what the public
            // site is serving, so the CDN/ISR copy has to be dropped even
            // though no status transition happened.
            if ($page->isLive()) {
                ContentChanged::dispatch($page->slug, 'page.updated');
            }

            return $page;
        });
    }

    /**
     * Replaces the page's block tree with the supplied list. Blocks carrying
     * a known `ulid` are updated in place so their identity survives a save;
     * anything absent from the payload is deleted. Order is the array order —
     * `position` is never sent by the client.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function syncBlocks(ContentPage $page, array $blocks): void
    {
        /** @var list<int> $keptIds */
        $keptIds = [];

        foreach ($blocks as $position => $payload) {
            $attributes = [
                'type' => $payload['type'],
                'position' => $position,
                'data' => $payload['data'] ?? [],
                'data_bn' => $payload['data_bn'] ?? null,
                'is_visible' => $payload['is_visible'] ?? true,
                'media_id' => $this->mediaIdFor($payload['media_ulid'] ?? null),
            ];

            $ulid = $payload['ulid'] ?? null;
            $existing = is_string($ulid) && $ulid !== ''
                ? ContentBlock::query()->where('content_page_id', $page->id)->where('ulid', $ulid)->first()
                : null;

            if ($existing !== null) {
                $existing->update($attributes);
                $keptIds[] = $existing->id;

                continue;
            }

            /** @var ContentBlock $created */
            $created = $page->blocks()->create($attributes);
            $keptIds[] = $created->id;
        }

        ContentBlock::query()
            ->where('content_page_id', $page->id)
            ->whereNotIn('id', $keptIds)
            ->delete();
    }

    /**
     * Snapshots the page's editable fields plus its full block tree, so a
     * restore needs no join against live data. Media is recorded by ULID,
     * not internal id — a revision is readable through the admin API and
     * must not leak primary keys across the boundary.
     */
    private function captureRevision(ContentPage $page, User $editor, ?string $changeNote): void
    {
        $page->load(['blocks', 'blocks.media']);

        ContentPageRevision::create([
            'content_page_id' => $page->id,
            'revision_number' => $page->revision_number,
            'title' => $page->title,
            'title_bn' => $page->title_bn,
            'excerpt' => $page->excerpt,
            'excerpt_bn' => $page->excerpt_bn,
            'seo_title' => $page->seo_title,
            'seo_title_bn' => $page->seo_title_bn,
            'seo_description' => $page->seo_description,
            'seo_description_bn' => $page->seo_description_bn,
            'blocks_snapshot' => $page->blocks->map(fn (ContentBlock $block): array => [
                'ulid' => $block->ulid,
                'type' => $block->type,
                'position' => $block->position,
                'data' => $block->data,
                'data_bn' => $block->data_bn,
                'media_ulid' => $block->media?->ulid,
                'is_visible' => $block->is_visible,
            ])->all(),
            'status_at_capture' => $page->status,
            'change_note' => $changeNote,
            'created_by_user_id' => $editor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function resolveOgImage(array $attributes): array
    {
        if (array_key_exists('og_image_media_ulid', $attributes)) {
            $attributes['og_image_media_id'] = $this->mediaIdFor($attributes['og_image_media_ulid']);
            unset($attributes['og_image_media_ulid']);
        }

        return $attributes;
    }

    private function mediaIdFor(mixed $ulid): ?int
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $id = MediaFile::query()->where('ulid', $ulid)->value('id');

        return $id === null ? null : (int) $id;
    }
}
