<?php

namespace App\Domain\Content\Events;

use App\Domain\Content\Listeners\RevalidateFrontendContent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something the public site is serving has changed.
 *
 * Content is CDN- and ISR-cached hard (every public content response is
 * ETagged), which is what makes editing it feel like it "didn't take" until
 * the cache expires. This is the signal that drops the stale copy —
 * {@see RevalidateFrontendContent} turns it into a
 * revalidation ping at the Next.js site.
 *
 * Deliberately an event rather than a direct HTTP call from the Actions:
 * Content must not know that a Next.js frontend exists, and revalidation
 * failing must never fail the editor's save.
 */
class ContentChanged
{
    use Dispatchable;

    /**
     * @param  string|null  $slug  the page slug to revalidate, or null when the change
     *                             affects shared collections (sponsors, schedule, FAQs,
     *                             gallery, menus) that any page may render
     * @param  string  $reason  short machine-readable cause, e.g. `page.published`
     */
    public function __construct(
        public readonly ?string $slug,
        public readonly string $reason,
    ) {}
}
