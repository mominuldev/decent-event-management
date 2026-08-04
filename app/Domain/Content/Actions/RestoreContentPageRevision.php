<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\ContentPageRevision;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rolls a page's body back to an earlier snapshot.
 *
 * A restore replays the snapshot through {@see SaveContentPage}, so it writes
 * a *new* revision on top of the history rather than rewinding it — the
 * revision table stays append-only and "who restored what, when" is itself
 * recoverable. `status` is untouched: restoring the text of a live page must
 * not republish or unpublish it.
 */
class RestoreContentPageRevision
{
    public function __construct(private readonly SaveContentPage $saveContentPage) {}

    public function execute(
        ContentPage $page,
        ContentPageRevision $revision,
        User $actor,
        ?string $ip = null,
        ?string $requestId = null,
    ): ContentPage {
        if ($revision->content_page_id !== $page->id) {
            throw new InvalidArgumentException('That revision belongs to a different page.');
        }

        return DB::transaction(fn (): ContentPage => $this->saveContentPage->execute(
            $page,
            [
                'title' => $revision->title,
                'title_bn' => $revision->title_bn,
                'excerpt' => $revision->excerpt,
                'excerpt_bn' => $revision->excerpt_bn,
                'seo_title' => $revision->seo_title,
                'seo_title_bn' => $revision->seo_title_bn,
                'seo_description' => $revision->seo_description,
                'seo_description_bn' => $revision->seo_description_bn,
                'blocks' => $this->blocksFrom($revision),
            ],
            $actor,
            "Restored from revision #{$revision->revision_number}",
            $ip,
            $requestId,
        ));
    }

    /**
     * Snapshot rows carry the block ULIDs they had at capture time. Passing
     * them back through means a block that still exists is updated in place
     * instead of being deleted and recreated with a new public identifier.
     *
     * @return list<array<string, mixed>>
     */
    private function blocksFrom(ContentPageRevision $revision): array
    {
        /** @var list<array<string, mixed>> $snapshot */
        $snapshot = $revision->blocks_snapshot ?? [];

        return array_map(fn (array $block): array => [
            'ulid' => is_string($block['ulid'] ?? null) ? $block['ulid'] : null,
            'type' => $block['type'],
            'data' => $block['data'] ?? [],
            'data_bn' => $block['data_bn'] ?? null,
            'media_ulid' => $block['media_ulid'] ?? null,
            'is_visible' => $block['is_visible'] ?? true,
        ], $snapshot);
    }
}
