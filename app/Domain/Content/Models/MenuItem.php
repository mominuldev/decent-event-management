<?php

namespace App\Domain\Content\Models;

use App\Domain\Shared\Support\HasUlid;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory, HasUlid;

    protected $fillable = [
        'menu_id',
        'parent_id',
        'label',
        'label_bn',
        'content_page_id',
        'url',
        'target',
        'position',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<ContentPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'content_page_id');
    }

    /**
     * An internal page reference wins over a literal `url`, so renaming a
     * slug re-points every menu entry instead of leaving a dead link.
     * Returns null when the linked page is no longer live — the caller drops
     * the item rather than publishing a link into a 404.
     */
    public function resolvedUrl(): ?string
    {
        if ($this->content_page_id !== null) {
            $page = $this->relationLoaded('page') ? $this->page : $this->page()->first();

            if ($page === null || ! $page->isLive()) {
                return null;
            }

            return '/'.ltrim($page->slug, '/');
        }

        return $this->url;
    }
}
