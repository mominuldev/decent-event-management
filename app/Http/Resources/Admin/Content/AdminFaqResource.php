<?php

namespace App\Http\Resources\Admin\Content;

use App\Domain\Content\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Faq
 */
class AdminFaqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'question' => $this->question,
            'question_bn' => $this->question_bn,
            'answer' => $this->answer,
            'answer_bn' => $this->answer_bn,
            'category' => $this->category,
            'category_bn' => $this->category_bn,
            'position' => $this->position,
            'is_published' => $this->is_published,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
