<?php

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Models\Menu;
use Illuminate\Validation\Rule;

class MenuRequest extends ContentResourceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $menu = $this->route('menu');
        $menuId = $menu instanceof Menu ? $menu->id : null;

        return [
            // The public site fetches a region by this code, so it is an
            // identifier the frontend hard-codes — kebab, and unique.
            'code' => [
                $this->requiredOnCreate(), 'string', 'max:32',
                'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                Rule::unique('menus', 'code')->ignore($menuId),
            ],
            'name' => [$this->requiredOnCreate(), 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
