<?php

namespace App\Http\Requests\Admin\Content;

use Illuminate\Validation\Rule;

class MenuItemRequest extends ContentResourceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => [$this->requiredOnCreate(), 'string', 'max:120'],
            'label_bn' => ['nullable', 'string', 'max:120'],
            'parent_ulid' => ['nullable', 'string', Rule::exists('menu_items', 'ulid')],
            // An internal page reference and a literal URL are alternatives,
            // not a pair — MenuItem::resolvedUrl() prefers the page, so
            // accepting both would silently ignore one of them.
            'page_ulid' => ['nullable', 'string', 'prohibits:url', Rule::exists('content_pages', 'ulid')],
            'url' => ['nullable', 'string', 'max:255'],
            'target' => ['sometimes', 'string', Rule::in(['_self', '_blank'])],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_visible' => ['sometimes', 'boolean'],
        ];
    }
}
