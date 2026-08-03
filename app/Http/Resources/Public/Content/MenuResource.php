<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Menu
 */
class MenuResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * Nesting cap. `menu_items.parent_id` is a self-referencing foreign key,
     * so a mis-edited row could in principle form a cycle; this bounds the
     * recursion rather than letting a navigation menu hang a request.
     */
    private const int MAX_DEPTH = 5;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, MenuItem> $items */
        $items = $this->relationLoaded('items') ? $this->items : collect();

        // An entry pointing at a page that is no longer live resolves to a
        // null URL — drop it rather than publish a link into a 404. Dropping
        // a parent drops its whole subtree, since the tree is built downward
        // from the surviving roots.
        $visible = $items->filter(
            fn (MenuItem $item): bool => $item->is_visible && $item->resolvedUrl() !== null
        );

        /** @var Collection<string, Collection<int, MenuItem>> $byParent */
        $byParent = $visible->groupBy(fn (MenuItem $item): string => (string) ($item->parent_id ?? 'root'));

        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->localised($request, $this->name, $this->name_bn),
            'items' => $this->buildTree($request, $byParent, 'root', 1),
        ];
    }

    /**
     * @param  Collection<string, Collection<int, MenuItem>>  $byParent
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(Request $request, Collection $byParent, string $parentKey, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        /** @var Collection<int, MenuItem> $children */
        $children = $byParent->get($parentKey) ?? collect();

        return $children
            ->map(fn (MenuItem $item): array => MenuItemResource::make(
                $item,
                $this->buildTree($request, $byParent, (string) $item->id, $depth + 1),
            )->toArray($request))
            ->values()
            ->all();
    }
}
