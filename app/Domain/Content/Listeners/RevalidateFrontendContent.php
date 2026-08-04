<?php

namespace App\Domain\Content\Listeners;

use App\Domain\Content\Events\ContentChanged;
use App\Jobs\RevalidateFrontendContentJob;

/**
 * Turns a {@see ContentChanged} into an ISR revalidation ping at the public
 * Next.js site. A no-op until `CONTENT_REVALIDATE_URL` is configured, so the
 * CMS is fully usable before the frontend repo exposes the hook.
 */
class RevalidateFrontendContent
{
    public function handle(ContentChanged $event): void
    {
        $url = config('services.frontend.revalidate_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        // afterCommit: the editor's save and its revision row must be
        // durable before the frontend is told to re-fetch, or a fast
        // revalidation could read the pre-save content back.
        RevalidateFrontendContentJob::dispatch($event->slug, $event->reason)->afterCommit();
    }
}
