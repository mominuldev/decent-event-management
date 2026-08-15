<?php

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (auth:attendee, abilities:attendee) is the real
        // gate; the request only reaches here already authenticated.
        return true;
    }

    /**
     * A cheap first gate only. The type that actually decides acceptance is
     * read from the file's magic bytes inside UpdateAttendeeProfilePhoto —
     * `mimes` here trusts the client's declared type, so it can stop an
     * obvious mistake but never an attacker. Mirrors StoreAttendeePhotoRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.max' => 'That image is over 4 MB — please choose a smaller one.',
            'photo.mimes' => 'Please choose a JPG, PNG or WebP image.',
        ];
    }
}
