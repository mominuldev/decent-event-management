<?php

namespace App\Http\Requests\Admin\Content;

use Illuminate\Validation\Rule;

class ScheduleItemRequest extends ContentResourceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => [$this->requiredOnCreate(), 'string', 'max:190'],
            'title_bn' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_bn' => ['nullable', 'string', 'max:2000'],
            'speaker_name' => ['nullable', 'string', 'max:150'],
            'speaker_name_bn' => ['nullable', 'string', 'max:150'],
            'speaker_title' => ['nullable', 'string', 'max:150'],
            'speaker_title_bn' => ['nullable', 'string', 'max:150'],
            'speaker_photo_media_ulid' => ['nullable', 'string', Rule::exists('media_files', 'ulid')],
            'venue' => ['nullable', 'string', 'max:190'],
            'venue_bn' => ['nullable', 'string', 'max:190'],
            'track' => ['nullable', 'string', 'max:32'],
            'starts_at' => [$this->requiredOnCreate(), 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            // Validated as a string, not `exists:event_sessions,code` — the
            // reference is deliberately soft (see ScheduleItem's class note),
            // and published copy has to survive a session being renamed.
            'event_session_code' => ['nullable', 'string', 'max:32'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
