<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Events\ContentChanged;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves a page through `draft → in_review → published → archived`
 * (ContentPage::TRANSITIONS). The only place `content_pages.status` changes —
 * an illegal move throws InvalidStateTransitionException rather than
 * silently writing the column, per the HasStateMachine rule in CLAUDE.md.
 */
class ChangeContentPageStatus
{
    /**
     * @param  Carbon|null  $publishedAt  when publishing, the go-live moment; a future
     *                                    timestamp schedules the page (scopeLive keeps it
     *                                    hidden until then). Defaults to now.
     */
    public function execute(
        ContentPage $page,
        string $to,
        User $actor,
        ?Carbon $publishedAt = null,
        ?string $ip = null,
        ?string $requestId = null,
    ): ContentPage {
        return DB::transaction(function () use ($page, $to, $actor, $publishedAt, $ip, $requestId): ContentPage {
            $wasLive = $page->isLive();
            $from = $page->status;

            $attributes = [];

            if ($to === 'published') {
                $attributes['published_at'] = $publishedAt ?? now();
                $attributes['published_by_user_id'] = $actor->id;
            }

            $page->transitionTo($to, $attributes);

            ActivityLog::create([
                'log_name' => 'content',
                'event' => 'status_changed',
                'description' => "Content page {$page->slug} moved from {$from} to {$to}",
                'causer_type' => $actor->getMorphClass(),
                'causer_id' => $actor->id,
                'subject_type' => $page->getMorphClass(),
                'subject_id' => $page->id,
                'properties' => [
                    'old' => ['status' => $from],
                    'new' => ['status' => $to, 'published_at' => $page->published_at?->toISOString()],
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            // Both directions matter: publishing makes a page appear, and
            // pulling one has to evict the copy the CDN is still serving.
            if ($wasLive || $page->isLive()) {
                ContentChanged::dispatch($page->slug, "page.{$to}");
            }

            return $page;
        });
    }
}
