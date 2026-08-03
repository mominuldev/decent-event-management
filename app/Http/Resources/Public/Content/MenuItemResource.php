<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\MenuItem;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MenuItem
 */
class MenuItemResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * Children arrive already rendered from {@see MenuResource}, which nests
     * the whole tree in memory from a single query.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $children;

    /**
     * @param  array<int, array<string, mixed>>  $children
     */
    public function __construct(MenuItem $resource, array $children = [])
    {
        parent::__construct($resource);

        $this->children = $children;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'label' => $this->localised($request, $this->label, $this->label_bn),
            'url' => $this->resolvedUrl(),
            'target' => $this->target,
            'position' => $this->position,
            'children' => $this->children,
        ];
    }
}
