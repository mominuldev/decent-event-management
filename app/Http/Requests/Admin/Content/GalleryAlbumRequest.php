<?php

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Models\GalleryAlbum;
use Illuminate\Validation\Rule;

class GalleryAlbumRequest extends ContentResourceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $album = $this->route('album');
        $albumId = $album instanceof GalleryAlbum ? $album->id : null;

        return [
            'slug' => [
                $this->requiredOnCreate(), 'string', 'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('gallery_albums', 'slug')->ignore($albumId),
            ],
            'title' => [$this->requiredOnCreate(), 'string', 'max:190'],
            'title_bn' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_bn' => ['nullable', 'string', 'max:2000'],
            'cover_media_ulid' => ['nullable', 'string', Rule::exists('media_files', 'ulid')],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
