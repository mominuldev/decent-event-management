<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A navigation region with its item tree nested in memory — the editor needs
 * the whole tree at once to reorder it, and menus are small.
 *
 * @mixin Menu
 */
class AdminMenuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'name_bn' => $this->name_bn,
            'is_active' => $this->is_active,
            'items' => $this->when(
                $this->relationLoaded('items'),
                fn (): array => $this->nest($request, null),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Builds the tree from the already-loaded flat item collection rather
     * than issuing a query per level.
     *
     * @return array<int, array<string, mixed>>
     */
    private function nest(Request $request, ?int $parentId): array
    {
        return $this->items
            ->where('parent_id', $parentId)
            ->sortBy('position')
            ->map(fn (MenuItem $item): array => array_merge(
                AdminMenuItemResource::make($item)->toArray($request),
                ['children' => $this->nest($request, $item->id)],
            ))
            ->values()
            ->all();
    }
}
