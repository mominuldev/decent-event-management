<?php

namespace App\Http\Controllers\Api\Admin\Content\Concerns;

use App\Domain\Shared\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;

/**
 * The CMS API speaks ULIDs; the tables hold internal ids. This is the
 * translation layer, kept in one place so a new content collection cannot
 * accidentally start accepting — or returning — a primary key.
 */
trait ResolvesContentReferences
{
    /**
     * Swaps a `*_media_ulid` input for the `*_media_id` column it maps to.
     * An explicit null clears the reference; an absent key leaves it alone,
     * which is what makes PATCH partial.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function resolveMediaUlid(array $validated, string $from, string $to): array
    {
        if (! array_key_exists($from, $validated)) {
            return $validated;
        }

        $validated[$to] = $this->idForUlid(MediaFile::class, $validated[$from]);
        unset($validated[$from]);

        return $validated;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function resolveUlid(array $validated, string $from, string $to, string $model): array
    {
        if (! array_key_exists($from, $validated)) {
            return $validated;
        }

        $validated[$to] = $this->idForUlid($model, $validated[$from]);
        unset($validated[$from]);

        return $validated;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function idForUlid(string $model, mixed $ulid): ?int
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $id = $model::query()->where('ulid', $ulid)->value('id');

        return $id === null ? null : (int) $id;
    }
}
