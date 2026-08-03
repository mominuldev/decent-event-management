<?php

namespace App\Http\Resources\Public\Content\Concerns;

use App\Domain\Content\Support\ContentLocale;
use Illuminate\Http\Request;

/**
 * Gives every public content resource the request's resolved locale.
 *
 * The controller resolves it once and stashes it on the request, so rendering
 * a hundred-item collection does not re-parse `Accept-Language` a hundred
 * times. Resources still fall back to resolving it themselves, so a resource
 * used outside a content controller cannot silently render the wrong language.
 */
trait ResolvesContentLocale
{
    protected function contentLocale(Request $request): string
    {
        $cached = $request->attributes->get(ContentLocale::REQUEST_ATTRIBUTE);

        return is_string($cached) ? $cached : ContentLocale::resolve($request);
    }

    /**
     * Localised value of a `field`/`field_bn` pair, falling back to English.
     */
    protected function localised(Request $request, ?string $english, ?string $bangla): ?string
    {
        return ContentLocale::pick($this->contentLocale($request), $english, $bangla);
    }
}
