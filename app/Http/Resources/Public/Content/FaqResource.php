<?php

namespace App\Http\Resources\Public\Content;

use App\Domain\Content\Models\Faq;
use App\Http\Resources\Public\Content\Concerns\ResolvesContentLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Faq
 */
class FaqResource extends JsonResource
{
    use ResolvesContentLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'question' => $this->localised($request, $this->question, $this->question_bn),
            'answer' => $this->localised($request, $this->answer, $this->answer_bn),
            'category' => $this->localised($request, $this->category, $this->category_bn),
            'position' => $this->position,
        ];
    }
}
