<?php

namespace App\Http\Requests\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared authorisation for the simple CMS collections — sponsors, schedule
 * items, FAQs, menus, gallery albums and items.
 *
 * They all key off the same two permissions, and each concrete request
 * differs only in its rules. Publishing one of these is a plain
 * `is_published` flag rather than a state machine, so it rides along with
 * `content.update`; `content.publish` guards page workflow specifically.
 */
abstract class ContentResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST') ? 'content.create' : 'content.update';

        return $this->user()?->can($permission) ?? false;
    }

    /** `required` when creating, `sometimes` when patching. */
    protected function requiredOnCreate(): string
    {
        return $this->isMethod('POST') ? 'required' : 'sometimes';
    }
}
