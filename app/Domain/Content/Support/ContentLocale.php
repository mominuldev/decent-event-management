<?php

namespace App\Domain\Content\Support;

use Illuminate\Http\Request;

/**
 * Locale resolution for the public content API.
 *
 * The CMS is bilingual by construction (docs/08 Phase 3.5): every editable
 * string is stored as a `field`/`field_bn` pair rather than a duplicated page
 * tree per locale. This resolves which half of each pair a given request
 * should see, and falls back to English whenever the Bangla side is empty —
 * so an editor can publish English first without the page rendering blank.
 */
final class ContentLocale
{
    public const string DEFAULT = 'en';

    /**
     * Where the resolved locale is stashed on the request, so a controller
     * resolves it once and every resource in the response reads it back
     * instead of re-parsing `Accept-Language` per item.
     */
    public const string REQUEST_ATTRIBUTE = 'content_locale';

    /** @var list<string> */
    public const array SUPPORTED = ['en', 'bn'];

    /**
     * An explicit `?locale=` wins over `Accept-Language`, which is only ever
     * a hint from the browser. Anything unsupported resolves to English
     * rather than erroring — a marketing page must always render.
     */
    public static function resolve(Request $request): string
    {
        $explicit = $request->query('locale');

        if (is_string($explicit) && in_array($explicit, self::SUPPORTED, true)) {
            return $explicit;
        }

        return self::fromAcceptLanguage((string) $request->header('Accept-Language', ''));
    }

    /**
     * Picks the highest-quality supported language from an Accept-Language
     * header, honouring `q=` weights (`bn;q=0.9, en;q=0.8` resolves to `bn`).
     */
    public static function fromAcceptLanguage(string $header): string
    {
        if ($header === '') {
            return self::DEFAULT;
        }

        $best = self::DEFAULT;
        $bestQuality = -1.0;

        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $tag = strtolower(trim($segments[0]));

            // `bn-BD` and `en-GB` are the primary subtag plus a region.
            $primary = explode('-', $tag)[0];

            if (! in_array($primary, self::SUPPORTED, true)) {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $segment) {
                if (str_starts_with(trim($segment), 'q=')) {
                    $quality = (float) substr(trim($segment), 2);
                }
            }

            if ($quality > $bestQuality) {
                $best = $primary;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    /**
     * Returns the localised value of a `field`/`field_bn` pair, falling back
     * to the English column when the Bangla one is null or blank.
     */
    public static function pick(string $locale, ?string $english, ?string $bangla): ?string
    {
        if ($locale !== 'bn') {
            return $english;
        }

        return ($bangla !== null && trim($bangla) !== '') ? $bangla : $english;
    }

    /**
     * The array-valued counterpart of {@see pick()}, for the JSON `data` /
     * `data_bn` pair on content blocks.
     *
     * @param  array<string, mixed>|null  $english
     * @param  array<string, mixed>|null  $bangla
     * @return array<string, mixed>
     */
    public static function pickArray(string $locale, ?array $english, ?array $bangla): array
    {
        if ($locale !== 'bn') {
            return $english ?? [];
        }

        if ($bangla === null || $bangla === []) {
            return $english ?? [];
        }

        // Per-key fallback: a partially translated block keeps its English
        // values for the keys the editor has not filled in yet.
        return array_merge($english ?? [], array_filter(
            $bangla,
            fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        ));
    }
}
