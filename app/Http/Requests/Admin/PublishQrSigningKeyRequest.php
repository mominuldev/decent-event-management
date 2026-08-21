<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PublishQrSigningKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('qr.rotate_signing_key') ?? false;
    }

    /**
     * Only a key id — never key material. The server derives the public key
     * from the private half it already holds, so there is nothing here for
     * an operator to paste wrongly and no secret in a request body or an
     * access log.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'key_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
