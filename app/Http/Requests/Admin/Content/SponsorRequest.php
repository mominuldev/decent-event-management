<?php

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Models\Sponsor;
use Illuminate\Validation\Rule;

class SponsorRequest extends ContentResourceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->requiredOnCreate(), 'string', 'max:190'],
            'name_bn' => ['nullable', 'string', 'max:190'],
            'tier' => ['sometimes', 'string', Rule::in(Sponsor::TIERS)],
            'logo_media_ulid' => ['nullable', 'string', Rule::exists('media_files', 'ulid')],
            'website_url' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_bn' => ['nullable', 'string', 'max:2000'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
