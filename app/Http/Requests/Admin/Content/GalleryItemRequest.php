<?php

namespace App\Http\Requests\Admin\Content;

use Illuminate\Validation\Rule;

class GalleryItemRequest extends ContentResourceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Non-nullable in the schema: a gallery item *is* a picture, so
            // there is no meaningful item without one.
            'media_ulid' => [$this->requiredOnCreate(), 'string', Rule::exists('media_files', 'ulid')],
            'caption' => ['nullable', 'string', 'max:255'],
            'caption_bn' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'alt_text_bn' => ['nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
