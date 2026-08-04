<?php

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit share one rule set: on POST the editorial fields are
 * required, on PATCH each is optional but validated identically when present.
 *
 * `status` is not accepted here at all — publishing is a separate,
 * separately-permissioned endpoint (`content.publish`), so an editor with
 * `content.update` cannot push a page live by smuggling a field into a save.
 */
class ContentPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST') ? 'content.create' : 'content.update';

        return $this->user()?->can($permission) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $page = $this->route('page');
        $pageId = $page instanceof ContentPage ? $page->id : null;

        return [
            'slug' => [
                $required, 'string', 'max:160',
                // Lowercase kebab only: the slug is a URL segment on the
                // public site and a cache key, not free text.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_pages', 'slug')->ignore($pageId),
            ],
            'template' => ['sometimes', 'string', Rule::in(ContentPage::TEMPLATES)],
            'title' => [$required, 'string', 'max:190'],
            'title_bn' => ['nullable', 'string', 'max:190'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'excerpt_bn' => ['nullable', 'string', 'max:2000'],
            'seo_title' => ['nullable', 'string', 'max:190'],
            'seo_title_bn' => ['nullable', 'string', 'max:190'],
            'seo_description' => ['nullable', 'string', 'max:255'],
            'seo_description_bn' => ['nullable', 'string', 'max:255'],
            'og_image_media_ulid' => ['nullable', 'string', Rule::exists('media_files', 'ulid')],
            'is_indexable' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'change_note' => ['nullable', 'string', 'max:255'],

            // Omitting `blocks` leaves the existing tree alone; sending it
            // replaces the tree wholesale, in the order given.
            'blocks' => ['sometimes', 'array', 'max:60'],
            'blocks.*.ulid' => ['nullable', 'string', 'size:26'],
            'blocks.*.type' => ['required', 'string', Rule::in(ContentBlock::TYPES)],
            'blocks.*.data' => ['required', 'array'],
            'blocks.*.data_bn' => ['nullable', 'array'],
            'blocks.*.media_ulid' => ['nullable', 'string', Rule::exists('media_files', 'ulid')],
            'blocks.*.is_visible' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.',
        ];
    }
}
