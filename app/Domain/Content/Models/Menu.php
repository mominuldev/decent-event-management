<?php

namespace App\Domain\Content\Models;

use App\Domain\Shared\Support\HasUlid;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named navigation region — `primary`, `footer` — fetched by its stable
 * `code` rather than its ULID, since the public site hard-codes which region
 * it is rendering.
 */
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory, HasUlid;

    protected $fillable = [
        'code',
        'name',
        'name_bn',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Every item in the menu, at any depth. The public API nests these in
     * memory rather than issuing a query per level.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }
}
