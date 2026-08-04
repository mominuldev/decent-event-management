<?php

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Actions\UploadContentMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * First-pass gate only — a size and shape check so an 80 MB file is rejected
 * before it reaches PHP's image decoder. The type decision that matters is
 * made from magic bytes inside {@see UploadContentMedia};
 * `mimetypes:` here is not trusted on its own.
 */
class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('content.manage_media') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:8192', 'mimetypes:image/jpeg,image/png,image/webp'],
            'collection' => ['sometimes', 'string', Rule::in(UploadContentMedia::COLLECTIONS)],
        ];
    }
}
