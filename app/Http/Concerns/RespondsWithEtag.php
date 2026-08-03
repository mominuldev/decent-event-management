<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CDN-friendly conditional responses for the public content API
 * (docs/08 Phase 3.5 — "cache-tagged and CDN-friendly with ETags").
 *
 * The ETag is derived from the rendered body, so it changes exactly when the
 * content does — including when the resolved locale changes, since that
 * changes the body too. `Vary: Accept-Language` keeps a shared cache from
 * serving a Bangla body to an English reader.
 */
trait RespondsWithEtag
{
    /**
     * Public, cacheable content. Returns a bodyless 304 when the caller's
     * `If-None-Match` still matches.
     */
    protected function withPublicCache(Request $request, Response $response, int $maxAgeSeconds = 60): Response
    {
        $content = $response->getContent();

        $response->setEtag(hash('xxh128', $content === false ? '' : $content));
        $response->setPublic();
        $response->setMaxAge($maxAgeSeconds);
        $response->headers->set('Vary', 'Accept-Language');

        // Mutates the response into a 304 with an empty body when the
        // validator matches; otherwise leaves it untouched.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Unpublished content fetched with a preview token. Never cached by a
     * shared cache and never indexed — a preview URL that leaks must not
     * leave a copy behind in a CDN or a search index.
     */
    protected function withPreviewHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
