<?php

namespace App\Http\Requests\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The editable half of a media row. The bytes, dimensions and collection are
 * fixed at upload — replacing a picture is a new upload — so the only thing
 * an editor changes after the fact is how it is described.
 */
class UpdateMediaRequest extends FormRequest
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
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_text_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
