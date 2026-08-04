<?php

namespace App\Http\Requests\Admin\Content;

class FaqRequest extends ContentResourceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => [$this->requiredOnCreate(), 'string', 'max:255'],
            'question_bn' => ['nullable', 'string', 'max:255'],
            'answer' => [$this->requiredOnCreate(), 'string', 'max:5000'],
            'answer_bn' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:48'],
            'category_bn' => ['nullable', 'string', 'max:48'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
