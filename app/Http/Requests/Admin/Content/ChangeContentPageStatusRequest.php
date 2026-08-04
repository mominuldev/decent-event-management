<?php

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\Models\ContentPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gated on `content.publish`, which the Event Manager holds and a plain
 * editor does not. Whether the *specific* move is legal is the state
 * machine's call, not this class's — an illegal transition is a 422 from
 * InvalidStateTransitionException, so the permitted map lives in exactly one
 * place (ContentPage::TRANSITIONS).
 */
class ChangeContentPageStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('content.publish') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_keys(ContentPage::TRANSITIONS))],
            // A future timestamp schedules the page: scopeLive() keeps it
            // hidden until the moment arrives, with no cron job involved.
            'published_at' => ['nullable', 'date'],
        ];
    }
}
