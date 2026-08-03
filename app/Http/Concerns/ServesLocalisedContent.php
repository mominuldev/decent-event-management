<?php

namespace App\Http\Concerns;

use App\Domain\Content\Support\ContentLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared plumbing for the public content controllers: resolve the request's
 * locale once, and produce the API's uniform error envelope for content that
 * the caller may not see.
 */
trait ServesLocalisedContent
{
    /**
     * Resolves the locale once per request and stashes it where the resources
     * can read it, so rendering a collection does not re-parse
     * `Accept-Language` for every item.
     */
    protected function stashLocale(Request $request): string
    {
        $locale = ContentLocale::resolve($request);

        $request->attributes->set(ContentLocale::REQUEST_ATTRIBUTE, $locale);

        return $locale;
    }

    /**
     * 404, never 403 — an unpublished page must be indistinguishable from one
     * that was never created (CLAUDE.md: "never leak stack traces or confirm
     * resource existence to an unauthorized caller"). Every caller of this
     * gets a byte-identical body, so response shape cannot be used to probe
     * for draft slugs either.
     */
    protected function contentNotFound(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'NOT_FOUND',
            'message' => 'Content not found.',
            'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
        ], Response::HTTP_NOT_FOUND);
    }
}
