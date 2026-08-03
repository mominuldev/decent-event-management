<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Models\ContentPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Visibility for the simple content types — sponsors, schedule items, FAQs,
 * gallery albums and items — which carry a plain `is_published` boolean.
 *
 * {@see ContentPage} deliberately does *not* use
 * this: a page has the full draft → review → published state machine plus
 * `published_at` scheduling, so its visibility rule is richer than a flag.
 *
 * @phpstan-require-extends Model
 */
trait IsPublishableContent
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
